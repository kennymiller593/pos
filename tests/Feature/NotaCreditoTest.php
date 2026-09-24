<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\SerieCorrelativo;
use App\Services\CajaService;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use Greenter\Model\Sale\Note;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioPos;
use Tests\Fakes\EnviadorSunatFalso;
use Tests\TestCase;

class NotaCreditoTest extends TestCase
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
        $this->darStock($this->producto, 10, 4.00);
        $this->abrirCaja();
    }

    private function venderBoleta(float $cantidad = 2, bool $esCredito = false, ?string $clienteId = null): Comprobante
    {
        $total = round($cantidad * 10.00, 2);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => $clienteId,
            'es_credito' => $esCredito,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => $esCredito ? [] : [['medio_pago_codigo' => 'efectivo', 'monto' => $total, 'referencia' => null]],
        ])->assertSessionHas('success');

        return Comprobante::where('empresa_id', $this->empresa->id)
            ->where('tipo_comprobante_codigo', '03')
            ->latest('creado_en')
            ->firstOrFail();
    }

    private function emitirNota(Comprobante $original, array $datos)
    {
        return $this->actingAs($this->admin)->post("/comprobantes/{$original->id}/nota-credito", $datos);
    }

    public function test_nota_total_repone_stock_devuelve_efectivo_y_se_envia_a_sunat(): void
    {
        $original = $this->venderBoleta(cantidad: 2);
        $this->assertSame(8.0, $this->stockDe($this->producto));

        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        $nota = Comprobante::where('comprobante_ref_id', $original->id)->firstOrFail();
        $this->assertSame('07', $nota->tipo_comprobante_codigo);
        $this->assertSame('BC01', $nota->serie);
        $this->assertSame('06', trim($nota->motivo_nota));
        $this->assertEqualsWithDelta(20.00, (float) $nota->total, 0.001);

        // stock repuesto
        $this->assertSame(10.0, $this->stockDe($this->producto));

        // el efectivo salio de la caja abierta
        $egreso = MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'egreso')->first();
        $this->assertNotNull($egreso);
        $this->assertEqualsWithDelta(20.00, (float) $egreso->monto, 0.001);

        // se envio a SUNAT como Note referenciando la boleta
        $this->assertInstanceOf(Note::class, $this->enviador->ultimoInvoice);
        /** @var Note $documento */
        $documento = $this->enviador->ultimoInvoice;
        $this->assertSame('03', $documento->getTipDocAfectado());
        $this->assertSame("B001-{$original->correlativo}", $documento->getNumDocfectado());
        $this->assertSame('06', $documento->getCodMotivo());
        $this->assertSame('aceptado', ComprobanteSunat::find($nota->id)->estado);
    }

    public function test_la_devolucion_sale_por_el_medio_de_la_venta_y_no_toca_el_efectivo_si_fue_yape(): void
    {
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '03',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [['medio_pago_codigo' => 'yape', 'monto' => 20.00, 'referencia' => 'OP-123']],
        ])->assertSessionHas('success');
        $original = Comprobante::where('empresa_id', $this->empresa->id)->where('tipo_comprobante_codigo', '03')->latest('creado_en')->firstOrFail();

        $apertura = AperturaCaja::where('caja_id', $this->caja->id)->whereNull('cerrada_en')->firstOrFail();
        $esperadoAntes = app(CajaService::class)->resumen($apertura)['esperado'];

        // sin indicar medio: se devuelve por Yape, como se cobro
        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        $egreso = MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'egreso')->firstOrFail();
        $this->assertSame('yape', $egreso->medio_pago_codigo);
        $this->assertEqualsWithDelta(20.00, (float) $egreso->monto, 0.001);

        $resumen = app(CajaService::class)->resumen($apertura);
        $this->assertEqualsWithDelta($esperadoAntes, $resumen['esperado'], 0.001);
        $this->assertEqualsWithDelta(20.00, $resumen['egresos_otros_medios'], 0.001);
        $this->assertEqualsWithDelta(0.0, $resumen['egresos'], 0.001);
    }

    public function test_se_puede_elegir_otro_medio_de_devolucion_con_referencia(): void
    {
        $original = $this->venderBoleta(cantidad: 1); // cobrada en efectivo

        $this->emitirNota($original, ['motivo' => '06', 'medio_pago_codigo' => 'transferencia', 'referencia' => 'BCP-778'])
            ->assertSessionHas('success');

        $egreso = MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'egreso')->firstOrFail();
        $this->assertSame('transferencia', $egreso->medio_pago_codigo);
        $this->assertSame('BCP-778', $egreso->referencia);

        $this->emitirNota($original, ['motivo' => '06', 'medio_pago_codigo' => 'bitcoin'])->assertSessionHasErrors('medio_pago_codigo');
    }

    public function test_sin_efectivo_suficiente_la_devolucion_en_efectivo_se_bloquea(): void
    {
        // la caja abrio con S/ 100; una venta de S/ 10 en efectivo deja 110
        $original = $this->venderBoleta(cantidad: 1);

        // se retira casi todo el efectivo
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'retiro', 'monto' => 105])
            ->assertSessionHas('success');

        $this->emitirNota($original, ['motivo' => '06', 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'suficiente efectivo'));
        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $original->id)->count());

        // por Yape si procede aunque no haya efectivo
        $this->emitirNota($original, ['motivo' => '06', 'medio_pago_codigo' => 'yape'])->assertSessionHas('success');
    }

    public function test_una_nota_pendiente_se_puede_reenviar_a_sunat(): void
    {
        $original = $this->venderBoleta(cantidad: 2);

        // la nota no llega a SUNAT en el momento
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'sin conexión', errorComunicacion: true,
        );
        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        $nota = Comprobante::where('comprobante_ref_id', $original->id)->firstOrFail();
        $this->assertSame('pendiente', ComprobanteSunat::find($nota->id)->estado);

        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: true, codigo: '0', mensaje: 'Aceptada', xml: '<xml/>', hash: 'HASH-NC',
        );

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$nota->id}/sunat")
            ->assertSessionHas('success');

        $this->assertSame('aceptado', ComprobanteSunat::find($nota->id)->estado);
        $this->assertInstanceOf(Note::class, $this->enviador->ultimoInvoice);
    }

    public function test_nota_parcial_acredita_solo_lo_devuelto_y_respeta_el_tope(): void
    {
        $original = $this->venderBoleta(cantidad: 2);
        $detalle = $original->detalles()->firstOrFail();

        $this->emitirNota($original, [
            'motivo' => '07',
            'items' => [['detalle_id' => $detalle->id, 'cantidad' => 1]],
        ])->assertSessionHas('success');

        $nota = Comprobante::where('comprobante_ref_id', $original->id)->firstOrFail();
        $this->assertEqualsWithDelta(10.00, (float) $nota->total, 0.001);
        $this->assertSame(9.0, $this->stockDe($this->producto));

        // intentar devolver 2 mas excede lo pendiente (solo queda 1)
        $this->emitirNota($original, [
            'motivo' => '07',
            'items' => [['detalle_id' => $detalle->id, 'cantidad' => 2]],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'queda por acreditar'));

        // devolver la unidad restante si procede
        $this->emitirNota($original, [
            'motivo' => '07',
            'items' => [['detalle_id' => $detalle->id, 'cantidad' => 1]],
        ])->assertSessionHas('success');

        $this->assertSame(10.0, $this->stockDe($this->producto));
    }

    public function test_nota_sobre_venta_al_credito_reduce_la_deuda_sin_tocar_caja(): void
    {
        $cliente = $this->crearCliente(limiteCredito: 100);
        $original = $this->venderBoleta(cantidad: 2, esCredito: true, clienteId: $cliente->id);

        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        $cuenta = CuentaPorCobrar::where('comprobante_id', $original->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $cuenta->monto_total, 0.001);
        $this->assertSame('pagado', $cuenta->estado);

        $this->assertSame(0, MovimientoCaja::where('empresa_id', $this->empresa->id)->where('tipo', 'egreso')->count());
    }

    public function test_sin_aceptacion_de_sunat_no_procede_la_nota(): void
    {
        $this->enviador->respuesta = new RespuestaSunat(
            aceptado: false, codigo: '', mensaje: 'sin conexión', errorComunicacion: true,
        );

        $original = $this->venderBoleta();

        $this->emitirNota($original, ['motivo' => '06'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'anulación'));

        $this->assertSame(0, Comprobante::where('comprobante_ref_id', $original->id)->count());
    }

    public function test_una_nota_de_credito_no_se_puede_anular(): void
    {
        $original = $this->venderBoleta();
        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        $nota = Comprobante::where('comprobante_ref_id', $original->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$nota->id}/anular", ['motivo' => 'prueba'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no se puede anular'));

        $this->assertSame('emitido', $nota->fresh()->estado);
    }

    public function test_la_nota_resta_en_el_dashboard_y_no_aparece_como_fila_del_listado(): void
    {
        $original = $this->venderBoleta(cantidad: 2); // S/ 20
        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        // el dashboard netea la venta con su devolucion
        $this->actingAs($this->admin)->get('/')->assertInertia(fn ($pagina) => $pagina
            ->component('Inicio')
            ->where('hoy.total', fn ($v) => abs($v) < 0.001)
            ->where('hoy.tickets', 1)
            ->where('hoy.margen', fn ($v) => abs($v) < 0.001));

        // el reporte de ventas tambien queda en cero
        $this->actingAs($this->admin)->get('/reportes?tipo=ventas')->assertInertia(fn ($pagina) => $pagina
            ->where('datos.resumen.1.valor', 'S/ 0.00'));

        // en /comprobantes la nota no es fila propia: viaja dentro de la boleta original
        $this->actingAs($this->admin)->get('/comprobantes')->assertInertia(fn ($pagina) => $pagina
            ->has('comprobantes.data', 1)
            ->where('comprobantes.data.0.id', $original->id)
            ->has('comprobantes.data.0.notas', 1)
            ->where('comprobantes.data.0.notas.0.serie', 'BC01'));
    }

    public function test_no_se_puede_acreditar_mas_que_el_total_original(): void
    {
        $original = $this->venderBoleta(cantidad: 2);

        $this->emitirNota($original, ['motivo' => '06'])->assertSessionHas('success');

        // una segunda nota total ya no tiene nada que acreditar
        $this->emitirNota($original, ['motivo' => '06'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'acreditaría más'));
    }
}
