<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\DetalleConsumoCapa;
use App\Models\SerieCorrelativo;
use App\Models\Stock;
use App\Models\Sucursal;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class VentaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_venta_contado_descuenta_stock_y_consume_fifo_cruzando_capas(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $capaVieja = $this->darStock($producto, 9, 2.00, diasAtras: 10);
        $capaNueva = $this->darStock($producto, 91, 2.20, diasAtras: 2);
        $this->abrirCaja(100);

        $respuesta = $this->venderContado($producto->presentaciones->first(), 11);

        $respuesta->assertRedirect()->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame('NV01', $comprobante->serie);
        $this->assertSame(1, $comprobante->correlativo);
        $this->assertEqualsWithDelta(38.50, (float) $comprobante->total, 0.001);
        $this->assertEqualsWithDelta(32.63, (float) $comprobante->total_gravado, 0.001);
        $this->assertEqualsWithDelta(5.87, (float) $comprobante->total_igv, 0.001);

        // stock descontado
        $this->assertEqualsWithDelta(89, $this->stockDe($producto), 0.001);

        // FIFO: agota la capa vieja (9) y toma 2 de la nueva
        $this->assertEqualsWithDelta(0, (float) $capaVieja->fresh()->cantidad_restante, 0.001);
        $this->assertEqualsWithDelta(89, (float) $capaNueva->fresh()->cantidad_restante, 0.001);

        $detalle = $comprobante->detalles()->firstOrFail();
        $this->assertSame(2, DetalleConsumoCapa::where('detalle_id', $detalle->id)->count());
        // costo real: (9 x 2.00 + 2 x 2.20) / 11
        $this->assertEqualsWithDelta(2.036364, (float) $detalle->costo_unitario, 0.000001);

        // pago ligado a la apertura de caja
        $pago = $comprobante->pagos()->firstOrFail();
        $this->assertSame('efectivo', $pago->medio_pago_codigo);
        $this->assertEqualsWithDelta(38.50, (float) $pago->monto, 0.001);
        $this->assertNotNull($pago->apertura_id);
    }

    public function test_precio_mayorista_se_aplica_automaticamente_desde_la_cantidad_minima(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $unidad = $producto->presentaciones->first();
        $unidad->update(['precio_mayorista' => 3.00, 'cantidad_mayorista' => 12]);
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja();

        $vender = fn (float $cantidad, float $total) => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => $cantidad]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => $total, 'referencia' => null]],
        ]);

        // 11 unidades: precio normal (11 x 3.50 = 38.50)
        $vender(11, 38.50)->assertSessionHas('success');

        // 12 unidades: se activa el mayorista (12 x 3.00 = 36.00)
        $vender(12, 36.00)->assertSessionHas('success');

        $comprobantes = Comprobante::where('empresa_id', $this->empresa->id)->orderBy('correlativo')->get();
        $this->assertEqualsWithDelta(38.50, (float) $comprobantes[0]->total, 0.001);
        $this->assertEqualsWithDelta(3.50, (float) $comprobantes[0]->detalles()->first()->precio_unitario, 0.001);
        $this->assertEqualsWithDelta(36.00, (float) $comprobantes[1]->total, 0.001);
        $this->assertEqualsWithDelta(3.00, (float) $comprobantes[1]->detalles()->first()->precio_unitario, 0.001);

        // pagar con el precio normal cuando corresponde mayorista es rechazado
        $vender(12, 42.00)->assertSessionHas('error');
    }

    public function test_el_cajero_puede_vender_con_precio_manual(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $unidad = $producto->presentaciones->first();
        $this->darStock($producto, 50, 2.00);
        $this->abrirCaja();

        $vender = fn (?float $precio, float $total) => $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 2, 'precio_unitario' => $precio]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => $total, 'referencia' => null]],
        ]);

        // rebajado a 3.00 y subido a 4.00: ambos aceptados con el precio enviado
        $vender(3.00, 6.00)->assertSessionHas('success');
        $vender(4.00, 8.00)->assertSessionHas('success');
        // sin precio manual: usa el de lista
        $vender(null, 7.00)->assertSessionHas('success');

        $precios = Comprobante::where('empresa_id', $this->empresa->id)
            ->orderBy('correlativo')->get()
            ->map(fn ($c) => (float) $c->detalles()->first()->precio_unitario);
        $this->assertEqualsWithDelta([3.00, 4.00, 3.50], $precios->all(), 0.001);

        // precio invalido rechazado por validacion
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 1, 'precio_unitario' => 0]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 1, 'referencia' => null]],
        ])->assertSessionHasErrors('items.0.precio_unitario');
    }

    public function test_la_venta_prefiere_la_serie_asignada_a_la_caja(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        // serie general de la sucursal y una especifica para la caja del turno
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'tipo_comprobante_codigo' => '00',
            'serie' => 'NV05',
            'correlativo' => 0,
        ]);
        SerieCorrelativo::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'caja_id' => $this->caja->id,
            'tipo_comprobante_codigo' => '00',
            'serie' => 'NV09',
            'correlativo' => 7,
        ]);

        $this->venderContado($producto->presentaciones->first(), 1)
            ->assertRedirect()->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame('NV09', $comprobante->serie);
        $this->assertSame(8, $comprobante->correlativo);
    }

    public function test_vender_desde_otra_sucursal_crea_su_propia_serie(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 1); // NV01-1 en la principal

        // cerrar el turno y pasar a una segunda sucursal con su caja
        AperturaCaja::whereNull('cerrada_en')->where('usuario_id', $this->admin->id)
            ->update(['cerrada_en' => now(), 'monto_cierre' => 0, 'monto_sistema' => 0]);

        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);
        $cajaSecundaria = Caja::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $secundaria->id,
            'nombre' => 'Caja 1',
            'activo' => true,
        ]);
        AperturaCaja::create([
            'empresa_id' => $this->empresa->id,
            'caja_id' => $cajaSecundaria->id,
            'usuario_id' => $this->admin->id,
            'monto_inicial' => 0,
        ]);

        // stock en la secundaria
        Stock::create([
            'empresa_id' => $this->empresa->id,
            'producto_id' => $producto->id,
            'sucursal_id' => $secundaria->id,
            'cantidad' => 10,
        ]);
        CapaCosto::create([
            'empresa_id' => $this->empresa->id,
            'producto_id' => $producto->id,
            'sucursal_id' => $secundaria->id,
            'cantidad_inicial' => 10,
            'cantidad_restante' => 10,
            'costo_unitario' => 2.00,
            'fecha_ingreso' => now(),
        ]);

        $this->venderContado($producto->presentaciones->first(), 2)
            ->assertRedirect()->assertSessionHas('success');

        // la venta cayo en la secundaria con la siguiente serie libre
        $comprobante = Comprobante::where('sucursal_id', $secundaria->id)->firstOrFail();
        $this->assertSame('NV02', $comprobante->serie);
        $this->assertSame(1, $comprobante->correlativo);
    }

    public function test_correlativo_incrementa_por_venta(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 50, 3.00);
        $this->abrirCaja();

        $presentacion = $producto->presentaciones->first();
        $this->venderContado($presentacion, 1);
        $this->venderContado($presentacion, 1);

        $correlativos = Comprobante::where('empresa_id', $this->empresa->id)
            ->orderBy('correlativo')->pluck('correlativo')->all();

        $this->assertSame([1, 2], $correlativos);
    }

    public function test_venta_sin_stock_suficiente_es_rechazada(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 3, 2.00);
        $this->abrirCaja();

        $respuesta = $this->venderContado($producto->presentaciones->first(), 10);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertEqualsWithDelta(3, $this->stockDe($producto), 0.001);
    }

    public function test_pagos_que_no_cuadran_son_rechazados(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        $respuesta = $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 99, 'referencia' => null]],
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_venta_sin_caja_abierta_es_rechazada(): void
    {
        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 2.00);

        $respuesta = $this->venderContado($producto->presentaciones->first(), 1);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_venta_con_descuento_por_linea(): void
    {
        // 2 x S/3.50 = 7.00, con descuento de 1.00 -> total 6.00
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();

        $respuesta = $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2, 'descuento' => 1.00]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 6.00, 'referencia' => null]],
        ]);

        $respuesta->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(6.00, (float) $comprobante->total, 0.001);
        $this->assertEqualsWithDelta(1.00, (float) $comprobante->total_descuentos, 0.001);
        // el IGV se calcula sobre el importe rebajado: 6.00 / 1.18
        $this->assertEqualsWithDelta(5.08, (float) $comprobante->total_gravado, 0.001);
        $this->assertEqualsWithDelta(0.92, (float) $comprobante->total_igv, 0.001);

        $detalle = $comprobante->detalles()->firstOrFail();
        $this->assertEqualsWithDelta(1.00, (float) $detalle->descuento, 0.001);
        $this->assertEqualsWithDelta(6.00, (float) $detalle->total, 0.001);

        // descuento igual o mayor al importe de la linea -> rechazado
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2, 'descuento' => 7.00]],
            'pagos' => [['medio_pago_codigo' => 'efectivo', 'monto' => 0.01, 'referencia' => null]],
        ])->assertSessionHas('error');

        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_venta_con_pagos_mixtos(): void
    {
        // 10 unidades a S/3.50 = S/35.00: paga 20 en efectivo y 15 por Yape
        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 20, 2.00);
        $apertura = $this->abrirCaja(100);

        $respuesta = $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 10]],
            'pagos' => [
                ['medio_pago_codigo' => 'efectivo', 'monto' => 20.00, 'referencia' => null],
                ['medio_pago_codigo' => 'yape', 'monto' => 15.00, 'referencia' => 'YP-777'],
            ],
        ]);

        $respuesta->assertSessionHas('success');

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame(2, $comprobante->pagos()->count());

        // al arqueo solo entra el efectivo (20), no el yape
        $efectivoEnCaja = (float) $apertura->pagos()->where('medio_pago_codigo', 'efectivo')->sum('monto');
        $this->assertEqualsWithDelta(20.00, $efectivoEnCaja, 0.001);

        $yape = $comprobante->pagos()->where('medio_pago_codigo', 'yape')->firstOrFail();
        $this->assertSame('YP-777', $yape->referencia);

        // pagos mixtos que no cuadran (20 + 10 = 30 != 35) son rechazados
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => null,
            'es_credito' => false,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 10]],
            'pagos' => [
                ['medio_pago_codigo' => 'efectivo', 'monto' => 20.00, 'referencia' => null],
                ['medio_pago_codigo' => 'yape', 'monto' => 10.00, 'referencia' => 'YP-778'],
            ],
        ])->assertSessionHas('error');

        $this->assertSame(1, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_venta_credito_crea_cuenta_por_cobrar_sin_pagos(): void
    {
        $producto = $this->crearProducto(precio: 30.00);
        $this->darStock($producto, 50, 20.00);
        $this->abrirCaja();
        $cliente = $this->crearCliente(limiteCredito: 100);

        $respuesta = $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [],
        ]);

        $respuesta->assertSessionHas('success');

        $cuenta = CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();
        $this->assertSame('pendiente', $cuenta->estado);
        $this->assertEqualsWithDelta(60, (float) $cuenta->monto_total, 0.001);

        $comprobante = $cuenta->comprobante;
        $this->assertTrue($comprobante->es_credito);
        $this->assertSame(0, $comprobante->pagos()->count());
    }

    public function test_ticket_del_comprobante_en_pdf(): void
    {
        // logo subido: debe incrustarse en el ticket sin romper la generacion
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $rutaLogo = 'logos/test-ticket-' . random_int(1000, 9999) . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($rutaLogo, $png);
        $this->empresa->update(['logo_url' => "/storage/{$rutaLogo}"]);

        // la caja usa tiketera de 58mm: el ticket debe adaptarse
        $this->caja->update(['ancho_ticket' => 58]);

        $producto = $this->crearProducto(precio: 3.50);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 2);

        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();

        // la venta comparte la URL del ticket para imprimir al instante
        $this->assertSame(route('comprobantes.ticket', $comprobante), session('ticket'));

        $respuesta = $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/ticket");

        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());

        // otra empresa no puede verlo
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/ticket")->assertForbidden();

        \Illuminate\Support\Facades\Storage::disk('public')->delete($rutaLogo);
    }

    public function test_historial_de_compras_del_producto(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $unidad = $producto->presentaciones->first();

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => null,
            'serie_numero' => 'F001-000555',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 5, 'costo_unitario' => 2.00]],
        ]);

        // vender 2 consume la capa de esa compra: quedan 3 del lote
        $this->abrirCaja();
        $this->venderContado($unidad, 2);

        $respuesta = $this->actingAs($this->admin)->getJson("/pos/productos/{$producto->id}/historial");

        $respuesta->assertOk();
        $datos = $respuesta->json();
        $this->assertCount(1, $datos['compras']);
        $this->assertSame('F001-000555', $datos['compras'][0]['documento']);
        $this->assertEqualsWithDelta(2.00, $datos['compras'][0]['costo_unitario'], 0.001);
        $this->assertEqualsWithDelta(5, $datos['compras'][0]['cantidad'], 0.001);
        $this->assertEqualsWithDelta(3, $datos['compras'][0]['stock_restante'], 0.001);

        // otra empresa no puede consultarlo
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->getJson("/pos/productos/{$producto->id}/historial")->assertForbidden();
    }

    public function test_credito_es_bloqueado_al_exceder_el_limite_y_sin_linea(): void
    {
        $producto = $this->crearProducto(precio: 30.00);
        $this->darStock($producto, 50, 20.00);
        $this->abrirCaja();
        $conLimite = $this->crearCliente(limiteCredito: 50);
        $sinLinea = $this->crearCliente(limiteCredito: 0);

        $payload = fn ($clienteId) => [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $clienteId,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]], // 60 > 50
            'pagos' => [],
        ];

        $this->actingAs($this->admin)->post('/pos/ventas', $payload($conLimite->id))->assertSessionHas('error');
        $this->actingAs($this->admin)->post('/pos/ventas', $payload($sinLinea->id))->assertSessionHas('error');

        $this->assertSame(0, CuentaPorCobrar::where('empresa_id', $this->empresa->id)->count());
    }
}
