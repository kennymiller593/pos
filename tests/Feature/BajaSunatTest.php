<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\SerieCorrelativo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

class BajaSunatTest extends TestCase
{
    use CreaEscenarioPos;

    private EnviadorSunatFalso $enviador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->crearEscenarioBase();

        $this->empresa->update([
            'certificado_digital' => 'CERTIFICADO-DE-PRUEBA',
            'clave_certificado' => 'clave123',
            'usuario_sol' => 'MODDATOS',
            'clave_sol' => 'moddatos',
            'entorno_sunat' => 'beta',
            'facturacion_electronica' => true,
        ]);

        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '03',
            'serie' => 'B001',
            'correlativo' => 0,
        ]);

        $this->enviador = new EnviadorSunatFalso();
        $this->app->instance(EnviadorSunat::class, $this->enviador);

        // por defecto, el envio original resulta aceptado
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH',
        );
    }

    private function vender(string $tipo, ?string $clienteId = null): Comprobante
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 10, 4.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => $tipo,
            'cliente_id' => $clienteId,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10.00, 'referencia' => null]],
        ])->assertSessionHas('success');

        return Comprobante::where('empresa_id', $this->empresa->id)
            ->where('tipo_comprobante_codigo', $tipo)
            ->latest('creado_en')
            ->firstOrFail();
    }

    private function clienteRuc(): Cliente
    {
        return Cliente::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento_codigo' => '6',
            'numero_documento' => '20123456789',
            'nombre' => 'Empresa Cliente SAC',
            'limite_credito' => 0,
        ]);
    }

    private function anular(Comprobante $comprobante)
    {
        return $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", [
            'motivo' => 'error de digitación',
        ]);
    }

    public function test_anular_boleta_aceptada_envia_resumen_con_condicion_de_baja(): void
    {
        $comprobante = $this->vender('03');

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-RC-1', enProceso: true,
        );
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Baja aceptada', cdrZip: 'zip', ticket: 'TICKET-RC-1',
        );

        $this->anular($comprobante)->assertSessionHas('success');

        // se envio un resumen diario con condicion 3 (baja de boleta)
        $this->assertInstanceOf(Summary::class, $this->enviador->ultimaBaja);
        $detalle = $this->enviador->ultimaBaja->getDetails()[0];
        $this->assertSame('3', $detalle->getEstado());
        $this->assertSame('B001-1', $detalle->getSerieNro());

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('baja', $registro->estado);
        $this->assertSame('TICKET-RC-1', $registro->ticket);
        $this->assertSame('anulado', $comprobante->fresh()->estado);
    }

    public function test_anular_factura_aceptada_envia_comunicacion_de_baja_con_motivo(): void
    {
        $comprobante = $this->vender('01', $this->clienteRuc()->id);

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-RA-1', enProceso: true,
        );
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'TICKET-RA-1',
        );

        $this->anular($comprobante)->assertSessionHas('success');

        $this->assertInstanceOf(Voided::class, $this->enviador->ultimaBaja);
        $detalle = $this->enviador->ultimaBaja->getDetails()[0];
        $this->assertSame('01', $detalle->getTipoDoc());
        $this->assertSame('error de digitación', $detalle->getDesMotivoBaja());

        $this->assertSame('baja', ComprobanteSunat::find($comprobante->id)->estado);
        $this->assertSame('anulado', $comprobante->fresh()->estado);
    }

    public function test_baja_en_proceso_se_confirma_despues_con_el_boton_de_consulta(): void
    {
        $comprobante = $this->vender('03');

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-2', enProceso: true,
        );
        // la consulta inmediata aun no resuelve
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'Procesando', ticket: 'TICKET-2', enProceso: true,
        );

        $this->anular($comprobante)->assertSessionHas('success');

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('aceptado', $registro->estado); // sigue aceptado hasta confirmar
        $this->assertSame('TICKET-2', $registro->ticket);
        $this->assertSame('anulado', $comprobante->fresh()->estado);

        // mas tarde SUNAT confirma y la consulta manual lo refleja
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'TICKET-2',
        );

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/sunat")
            ->assertSessionHas('success');

        $this->assertSame('baja', $registro->fresh()->estado);
    }

    public function test_pasados_siete_dias_la_anulacion_se_bloquea(): void
    {
        $comprobante = $this->vender('03');

        $comprobante->forceFill(['fecha_emision' => now()->subDays(10)->toDateString()])->save();

        $this->anular($comprobante)->assertSessionHas('error', fn ($m) => str_contains($m, 'nota de crédito'));

        $this->assertSame('emitido', $comprobante->fresh()->estado);
        $this->assertNull($this->enviador->ultimaBaja);
    }

    public function test_si_sunat_no_recibe_la_baja_no_se_anula(): void
    {
        $comprobante = $this->vender('03');

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'Servicio no disponible', errorComunicacion: true,
        );

        $this->anular($comprobante)->assertSessionHas('error');

        $this->assertSame('emitido', $comprobante->fresh()->estado);
        $this->assertSame('aceptado', ComprobanteSunat::find($comprobante->id)->estado);
    }

    public function test_un_comprobante_nunca_aceptado_se_anula_sin_comunicar_baja(): void
    {
        // el envio original no llega a SUNAT
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'sin conexión', errorComunicacion: true,
        );

        $comprobante = $this->vender('03');
        $this->assertSame('pendiente', ComprobanteSunat::find($comprobante->id)->estado);

        $this->anular($comprobante)->assertSessionHas('success');

        // el doble lanzaria si se hubiera intentado enviar la baja
        $this->assertNull($this->enviador->ultimaBaja);
        $this->assertSame('anulado', $comprobante->fresh()->estado);
    }
}
