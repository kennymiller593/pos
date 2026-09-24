<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\Producto;
use App\Models\SerieCorrelativo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\Sale\Invoice;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

/**
 * Nota de venta → boleta/factura, reglas del documento del cliente y
 * reemisión de comprobantes rechazados.
 */
class ConversionComprobanteTest extends TestCase
{
    use CreaEscenarioPos;

    private EnviadorSunatFalso $enviador;

    private Producto $producto;

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

        $this->enviador = new EnviadorSunatFalso;
        $this->app->instance(EnviadorSunat::class, $this->enviador);
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH',
        );

        $this->producto = $this->crearProducto(precio: 10.00);
        $this->darStock($this->producto, 200, 4.00);
        $this->abrirCaja();
    }

    private function venderNotaDeVenta(float $cantidad = 2, ?string $clienteId = null): Comprobante
    {
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $clienteId,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => round($cantidad * 10, 2), 'referencia' => null]],
        ])->assertSessionHas('success');

        // dentro de la transaccion del test todas las ventas comparten segundo: se toma la ultima nota de venta por correlativo
        return Comprobante::where('empresa_id', $this->empresa->id)
            ->where('tipo_comprobante_codigo', '00')
            ->orderByDesc('correlativo')
            ->firstOrFail();
    }

    private function clienteCon(string $tipo, string $numero): Cliente
    {
        return Cliente::create([
            'empresa_id' => $this->empresa->id,
            'tipo_documento_codigo' => $tipo,
            'numero_documento' => $numero,
            'nombre' => 'Cliente '.$numero,
            'limite_credito' => 0,
        ]);
    }

    private function convertir(Comprobante $comprobante, string $tipo, ?string $clienteId = null)
    {
        return $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/convertir", [
            'tipo' => $tipo,
            'cliente_id' => $clienteId,
        ]);
    }

    public function test_una_nota_de_venta_se_convierte_en_boleta_y_se_envia_a_sunat(): void
    {
        $nota = $this->venderNotaDeVenta(cantidad: 2);
        $this->assertSame('NV01', $nota->serie);
        $this->assertSame(198.0, $this->stockDe($this->producto));

        $this->convertir($nota, '03')
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Boleta B001-000001'))
            ->assertSessionHas('ticket');

        $nota->refresh();
        $this->assertSame('03', $nota->tipo_comprobante_codigo);
        $this->assertSame('B001', $nota->serie);
        $this->assertSame(1, (int) $nota->correlativo);
        $this->assertSame('NV01-000001', $nota->sunat_respuesta['convertido_de']);

        // nada cambio en stock, pagos ni detalles: es la misma venta con otro comprobante
        $this->assertSame(198.0, $this->stockDe($this->producto));
        $this->assertSame(1, $nota->pagos()->count());
        $this->assertSame(1, $nota->detalles()->count());

        // se envio a SUNAT como boleta y quedo aceptada
        /** @var Invoice $invoice */
        $invoice = $this->enviador->ultimoInvoice;
        $this->assertSame('03', $invoice->getTipoDoc());
        $this->assertSame('aceptado', ComprobanteSunat::find($nota->id)->estado);

        $this->assertDatabaseHas('auditoria', ['entidad_id' => $nota->id, 'accion' => 'comprobante.convertido']);
    }

    public function test_la_factura_exige_un_cliente_con_ruc_valido(): void
    {
        $nota = $this->venderNotaDeVenta();

        $this->convertir($nota, '01')->assertSessionHas('error', fn ($m) => str_contains($m, 'RUC'));

        $rucMalo = $this->clienteCon('6', '20123456789');
        $this->convertir($nota, '01', $rucMalo->id)->assertSessionHas('error', fn ($m) => str_contains($m, 'no es válido'));
        $this->assertSame('00', $nota->fresh()->tipo_comprobante_codigo);

        $rucBueno = $this->clienteCon('6', '20123456786');
        $this->convertir($nota, '01', $rucBueno->id)->assertSessionHas('success');

        $nota->refresh();
        $this->assertSame('01', $nota->tipo_comprobante_codigo);
        $this->assertSame('F001', $nota->serie);
        $this->assertSame('20123456786', $nota->cliente_numero_doc);
        $this->assertSame($rucBueno->id, $nota->cliente_id);
    }

    public function test_la_boleta_desde_700_soles_exige_identificar_al_cliente(): void
    {
        $nota = $this->venderNotaDeVenta(cantidad: 80); // S/ 800

        $this->convertir($nota, '03')->assertSessionHas('error', fn ($m) => str_contains($m, '700'));

        $dni = $this->clienteCon('1', '45678912');
        $this->convertir($nota, '03', $dni->id);
        $this->assertNull(session('error'));
        $this->assertSame('45678912', $nota->fresh()->cliente_numero_doc);

        // la misma regla aplica al vender directamente como boleta
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 70]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 700, 'referencia' => null]],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, '700'));
    }

    public function test_un_dni_mal_formado_bloquea_la_boleta(): void
    {
        $dniMalo = $this->clienteCon('1', '4567891'); // 7 digitos
        $nota = $this->venderNotaDeVenta(clienteId: $dniMalo->id);

        $this->convertir($nota, '03')->assertSessionHas('error', fn ($m) => str_contains($m, 'DNI'));
    }

    public function test_fuera_de_plazo_no_se_convierte(): void
    {
        $nota = $this->venderNotaDeVenta();
        $nota->forceFill(['fecha_emision' => now()->subDays(8)->toDateString()])->save();

        $this->convertir($nota, '03')->assertSessionHas('error', fn ($m) => str_contains($m, 'días'));
        $this->assertSame('00', $nota->fresh()->tipo_comprobante_codigo);
    }

    public function test_solo_notas_de_venta_emitidas_se_convierten(): void
    {
        $nota = $this->venderNotaDeVenta();
        $this->convertir($nota, '03')->assertSessionHas('success');

        // ya es boleta: no se vuelve a convertir
        $this->convertir($nota, '01')->assertSessionHas('error');

        $otra = $this->venderNotaDeVenta();
        $this->actingAs($this->admin)->post("/comprobantes/{$otra->id}/anular", ['motivo' => 'error']);
        $this->convertir($otra, '03')->assertSessionHas('error', fn ($m) => str_contains($m, 'anulada'));
    }

    public function test_un_vendedor_no_puede_convertir(): void
    {
        $nota = $this->venderNotaDeVenta();
        $vendedor = $this->crearUsuario('vendedor', 'vendedor'.random_int(10000, 99999).'@test.local');

        $this->actingAs($vendedor)->post("/comprobantes/{$nota->id}/convertir", ['tipo' => '03'])->assertForbidden();
    }

    public function test_un_rechazado_se_corrige_desde_la_ficha_del_cliente_y_se_reenvia_con_el_mismo_numero(): void
    {
        $cliente = $this->clienteCon('1', '1234567'); // DNI mal digitado: 7 cifras

        // la venta como boleta pasa (importe menor a 700 no exige documento) pero el DNI va mal
        $cliente->forceFill(['numero_documento' => '1234567'])->save();
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '2801', mensaje: 'El DNI del cliente no es válido', xml: '<xml/>', hash: 'H', errorComunicacion: false,
        );
        // se salta la validacion de la venta creando el cliente sin documento y corrigiendo el snapshot despues
        $cliente->forceFill(['tipo_documento_codigo' => '0', 'numero_documento' => null])->save();
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => $cliente->id,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10, 'referencia' => null]],
        ])->assertSessionHas('success');

        $boleta = Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();
        $this->assertSame('rechazado', ComprobanteSunat::find($boleta->id)->estado);

        // el reenvio simple no corrige nada; "corregir y reenviar" toma los datos nuevos del cliente
        $cliente->forceFill(['tipo_documento_codigo' => '1', 'numero_documento' => '12345678'])->save();
        $this->enviador->respuesta = new RespuestaSunat(aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'H2');

        $this->actingAs($this->admin)->post("/comprobantes/{$boleta->id}/reemitir")->assertSessionHas('success');

        $boleta->refresh();
        $this->assertSame('12345678', $boleta->cliente_numero_doc);
        $this->assertSame('1', trim($boleta->cliente_tipo_doc));
        $this->assertSame('B001', $boleta->serie); // mismo numero
        $registro = ComprobanteSunat::find($boleta->id);
        $this->assertSame('aceptado', $registro->estado);
        $this->assertSame(2, (int) $registro->intentos);
    }

    public function test_reemitir_solo_aplica_a_rechazados(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(aceptado: false, codigo: '', mensaje: 'caído', errorComunicacion: true);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 10, 'referencia' => null]],
        ]);
        $boleta = Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();

        $this->actingAs($this->admin)->post("/comprobantes/{$boleta->id}/reemitir")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'rechazado'));
    }

    public function test_el_documento_del_cliente_se_valida_al_guardarlo(): void
    {
        $base = ['nombre' => 'Juan', 'direccion' => null, 'telefono' => null, 'email' => null, 'limite_credito' => 0];

        $this->actingAs($this->admin)->post('/clientes', [...$base, 'tipo_documento_codigo' => '1', 'numero_documento' => '1234'])
            ->assertSessionHasErrors('numero_documento');
        $this->actingAs($this->admin)->post('/clientes', [...$base, 'tipo_documento_codigo' => '6', 'numero_documento' => '20123456789'])
            ->assertSessionHasErrors('numero_documento');
        $this->actingAs($this->admin)->post('/clientes', [...$base, 'tipo_documento_codigo' => '6', 'numero_documento' => '20123456786'])
            ->assertSessionHasNoErrors();

        // tambien desde el alta rapida del POS
        $this->actingAs($this->admin)->postJson('/pos/clientes', ['tipo_documento_codigo' => '1', 'numero_documento' => '123', 'nombre' => 'Ana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_documento');
    }
}
