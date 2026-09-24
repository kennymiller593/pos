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

    public function test_baja_en_proceso_no_anula_hasta_que_sunat_la_confirme(): void
    {
        $comprobante = $this->vender('03');
        $producto = $comprobante->detalles()->first()->producto;
        $this->assertSame(9.0, $this->stockDe($producto));

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-2', enProceso: true,
        );
        // la consulta inmediata aun no resuelve
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'Procesando', ticket: 'TICKET-2', enProceso: true,
        );

        $this->anular($comprobante)->assertSessionHas('success', fn ($m) => str_contains($m, 'procesando'));

        // el comprobante sigue vigente: ni stock repuesto ni pagos retirados
        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('baja_pendiente', $registro->estado);
        $this->assertSame('TICKET-2', $registro->ticket);
        $this->assertSame('emitido', $comprobante->fresh()->estado);
        $this->assertSame(9.0, $this->stockDe($producto));
        $this->assertSame(1, $comprobante->pagos()->count());

        // un segundo intento de anular no manda otra baja: solo vuelve a consultar
        $this->anular($comprobante);
        $this->assertSame(2, $this->enviador->consultasTicket);

        // mas tarde SUNAT confirma y la consulta manual completa la anulacion
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'TICKET-2',
        );

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/sunat")
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'confirmó'));

        $this->assertSame('baja', $registro->fresh()->estado);
        $comprobante->refresh();
        $this->assertSame('anulado', $comprobante->estado);
        $this->assertSame('error de digitación', $comprobante->motivo_anulacion);
        $this->assertSame($this->admin->id, $comprobante->anulado_por);
        $this->assertSame(10.0, $this->stockDe($producto));
        $this->assertSame(0, $comprobante->pagos()->count());
    }

    public function test_si_sunat_rechaza_la_baja_el_comprobante_sigue_vigente(): void
    {
        $comprobante = $this->vender('03');

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-3', enProceso: true,
        );
        // el ticket vuelve procesado con errores (statusCode 99 + CDR de rechazo)
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: false, codigo: '2320', mensaje: 'El comprobante no existe', ticket: 'TICKET-3',
        );

        $this->anular($comprobante)->assertSessionHas('error', fn ($m) => str_contains($m, '2320'));

        $registro = ComprobanteSunat::find($comprobante->id);
        $this->assertSame('aceptado', $registro->estado);
        $this->assertNull($registro->ticket);
        $this->assertSame('emitido', $comprobante->fresh()->estado);
    }

    public function test_el_comando_programado_confirma_las_bajas_en_proceso(): void
    {
        $comprobante = $this->vender('03');

        $this->enviador->respuestaBaja = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'En proceso', ticket: 'TICKET-4', enProceso: true,
        );
        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: false, codigo: '98', mensaje: 'Procesando', ticket: 'TICKET-4', enProceso: true,
        );
        $this->anular($comprobante);
        $this->assertSame('emitido', $comprobante->fresh()->estado);

        $this->enviador->respuestaTicket = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Baja aceptada', ticket: 'TICKET-4',
        );

        $this->artisan('sunat:sincronizar')->assertSuccessful();

        $this->assertSame('baja', ComprobanteSunat::find($comprobante->id)->estado);
        $this->assertSame('anulado', $comprobante->fresh()->estado);
    }

    public function test_no_se_anula_un_comprobante_con_notas_de_credito(): void
    {
        $comprobante = $this->vender('03');

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$comprobante->id}/nota-credito", ['motivo' => '06'])
            ->assertSessionHas('success');

        $this->anular($comprobante)->assertSessionHas('error', fn ($m) => str_contains($m, 'notas de crédito'));

        $this->assertSame('emitido', $comprobante->fresh()->estado);
        $this->assertNull($this->enviador->ultimaBaja);
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
