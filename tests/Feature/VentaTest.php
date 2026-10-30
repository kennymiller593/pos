<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\ComprobanteDetalle;
use App\Models\CuentaPorCobrar;
use App\Models\DetalleConsumoCapa;
use App\Models\SerieCorrelativo;
use App\Models\Stock;
use App\Models\Sucursal;
use Illuminate\Support\Facades\Storage;
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

    public function test_la_linea_lleva_la_unidad_de_la_presentacion_vendida(): void
    {
        // maíz en kilos (KGM) que también se vende por saco (SA) de 50 kg
        $producto = $this->crearProducto(precio: 3.00, atributos: ['unidad_base_codigo' => 'KGM', 'permite_fraccion' => true]);
        $producto->presentaciones()->first()->update(['unidad_codigo' => 'KGM']);
        $saco = $this->agregarPresentacion($producto, 'Saco x50', 50, 120.00);
        $saco->update(['unidad_codigo' => 'SA']);
        $this->darStock($producto, 500, 1.60);
        $this->abrirCaja();

        $this->venderContado($saco, 1)->assertSessionHas('success');
        $this->venderContado($producto->presentaciones()->where('es_default', true)->first(), 3)->assertSessionHas('success');

        $unidades = ComprobanteDetalle::whereIn('presentacion_id', $producto->presentaciones()->pluck('id'))
            ->orderBy('cantidad')->pluck('unidad_codigo', 'descripcion')->all();
        $this->assertSame(['SA', 'KGM'], array_values($unidades)); // 1 saco = SA, 3 kilos sueltos = KGM
        $this->assertSame(447.0, $this->stockDe($producto));
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

        // la lista encuentra el comprobante por su numero completo, con o sin ceros a la izquierda
        $serie = Comprobante::where('empresa_id', $this->empresa->id)->value('serie');
        $buscar = fn (string $texto) => collect($this->actingAs($this->admin)->get('/comprobantes?buscar='.urlencode($texto))->assertOk()
            ->viewData('page')['props']['comprobantes']['data'])->pluck('correlativo')->sort()->values()->all();
        $this->assertSame([2], $buscar("{$serie}-000002"));
        $this->assertSame([2], $buscar(strtolower($serie).'-2'));
        $this->assertSame([], $buscar("{$serie}-000009"));
        $this->assertSame([1, 2], $buscar($serie));
    }

    public function test_comprobantes_filtra_por_condicion_y_cuentas_por_cobrar_trae_lo_que_compro(): void
    {
        $producto = $this->crearProducto(precio: 20.00);
        $this->darStock($producto, 50, 10.00);
        $this->abrirCaja();
        $presentacion = $producto->presentaciones->first();
        $this->venderContado($presentacion, 1);

        // una venta al crédito a un cliente con línea
        $cliente = $this->crearCliente(limiteCredito: 500);
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $presentacion->id, 'cantidad' => 3]],
            'pagos' => [],
        ])->assertSessionHasNoErrors();

        $lista = fn (string $url) => collect($this->actingAs($this->admin)->get($url)->assertOk()->viewData('page')['props']['comprobantes']['data']);
        $this->assertCount(2, $lista('/comprobantes'));
        $this->assertSame([true], $lista('/comprobantes?condicion=credito')->pluck('es_credito')->all());
        $this->assertSame([false], $lista('/comprobantes?condicion=contado')->pluck('es_credito')->all());
        $this->assertCount(2, $lista('/comprobantes?condicion=otra'));

        // en Cuentas por cobrar viene el detalle de la venta y su id para el ticket
        $props = $this->actingAs($this->admin)->get('/cuentas-por-cobrar')->assertOk()->viewData('page')['props'];
        $cuenta = $props['cuentas']['data'][0];
        $this->assertNotEmpty($cuenta['comprobante']['id']);
        $this->assertCount(1, $cuenta['comprobante']['detalles']);
        $this->assertEqualsWithDelta(3, $cuenta['comprobante']['detalles'][0]['cantidad'], 0.001);
        $this->assertEqualsWithDelta(60, $cuenta['comprobante']['total'], 0.001);
        $this->assertArrayHasKey('NIU', $props['unidades']);
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
        $rutaLogo = 'logos/test-ticket-'.random_int(1000, 9999).'.png';
        Storage::disk('public')->put($rutaLogo, $png);
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

        Storage::disk('public')->delete($rutaLogo);
    }

    public function test_el_ticket_corto_sigue_siendo_vertical(): void
    {
        // una venta de un solo item en 80mm daba una pagina de ~61mm de alto: Chrome la tomaba
        // como horizontal y en la termica salia encogida a un tercio del papel
        $producto = $this->crearProducto(precio: 7.00);
        $this->darStock($producto, 10, 2.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 1);
        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();

        $pdf = $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/ticket")->assertOk()->getContent();
        $this->assertSame(1, preg_match('/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/', $pdf, $caja));
        $anchoMm = (float) $caja[1] / 72 * 25.4;
        $altoMm = (float) $caja[2] / 72 * 25.4;
        $this->assertEqualsWithDelta(80, $anchoMm, 0.5);
        $this->assertGreaterThanOrEqual($anchoMm + 4, $altoMm);
    }

    public function test_el_ticket_en_html_se_imprime_solo_al_ancho_de_la_caja(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('success');
        $comprobante = Comprobante::where('empresa_id', $this->empresa->id)->latest('creado_en')->firstOrFail();

        $respuesta = $this->actingAs($this->admin)->get("/comprobantes/{$comprobante->id}/ticket?formato=html");

        $respuesta->assertOk();
        $this->assertStringContainsString('text/html', $respuesta->headers->get('content-type'));
        $this->assertStringContainsString('window.print()', $respuesta->getContent());
        $this->assertStringContainsString('size: 80mm auto', $respuesta->getContent());
        $this->assertStringContainsString('NOTA DE VENTA', $respuesta->getContent());
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

        // la misma consulta desde Productos trae también las ventas del producto
        $datos = $this->actingAs($this->admin)->getJson("/productos/{$producto->id}/historial")->assertOk()->json();
        $this->assertSame(['compras' => true, 'ventas' => true], $datos['puede']);
        $this->assertCount(1, $datos['ventas']);
        $this->assertEqualsWithDelta(2, $datos['ventas'][0]['cantidad'], 0.001);
        $this->assertEqualsWithDelta(3.50, $datos['ventas'][0]['precio_unitario'], 0.001);
        $this->assertSame('Clientes varios', $datos['ventas'][0]['cliente']);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+-\d{6}$/', $datos['ventas'][0]['documento']);
        $this->assertFalse($datos['ventas'][0]['anulada']);

        // el vendedor no ve costos: solo la pestaña de ventas
        $vendedor = $this->crearUsuario('vendedor', 'vend'.random_int(1000, 999999).'@test.local');
        $datos = $this->actingAs($vendedor)->getJson("/productos/{$producto->id}/historial")->assertOk()->json();
        $this->assertSame(['compras' => false, 'ventas' => true], $datos['puede']);
        $this->assertSame([], $datos['compras']);
        $this->assertCount(1, $datos['ventas']);

        // otra empresa no puede consultarlo
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->getJson("/pos/productos/{$producto->id}/historial")->assertForbidden();
        $this->actingAs($this->admin)->getJson("/productos/{$producto->id}/historial")->assertForbidden();
    }

    public function test_el_historial_del_producto_solo_muestra_las_sucursales_que_el_usuario_puede_ver(): void
    {
        $producto = $this->crearProducto(precio: 3.50);
        $unidad = $producto->presentaciones->first();
        $tarapaca = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0002', 'nombre' => 'Tarapacá', 'activo' => true]);

        // una compra en cada sucursal y una venta en la principal
        foreach ([[$this->sucursal, 'F001-000001', 5], [$tarapaca, 'F001-000002', 4]] as [$sucursal, $numero, $cantidad]) {
            $this->actingAs($this->admin)->post('/compras', [
                'sucursal_id' => $sucursal->id,
                'proveedor_id' => null,
                'tipo_comprobante_codigo' => null,
                'serie_numero' => $numero,
                'fecha' => now()->toDateString(),
                'es_credito' => false,
                'items' => [['presentacion_id' => $unidad->id, 'cantidad' => $cantidad, 'costo_unitario' => 2.00]],
            ])->assertSessionHasNoErrors();
        }
        $this->abrirCaja();
        $this->venderContado($unidad, 2);

        // el admin sin restriccion, viendo "todas", ve ambas compras y de que sucursal es cada una
        $datos = $this->actingAs($this->admin)->getJson("/productos/{$producto->id}/historial")->assertOk()->json();
        $this->assertTrue($datos['multisucursal']);
        $this->assertSame(['Tarapacá', 'Principal'], collect($datos['compras'])->sortBy('cantidad')->pluck('sucursal')->values()->all());
        $this->assertSame(['Principal'], collect($datos['ventas'])->pluck('sucursal')->all());

        // si elige una sucursal en el selector, solo ve esa
        $this->actingAs($this->admin)->post('/sucursal-activa', ['sucursal_id' => $tarapaca->id]);
        $datos = $this->actingAs($this->admin)->getJson("/productos/{$producto->id}/historial")->assertOk()->json();
        $this->assertSame(['Tarapacá'], collect($datos['compras'])->pluck('sucursal')->all());
        $this->assertSame([], $datos['ventas']);

        // un admin que solo puede entrar a la principal no ve lo de Tarapacá (era el hueco de Agro Marañón)
        $limitado = $this->crearUsuario('admin', 'limitado'.random_int(1000, 999999).'@test.local');
        $limitado->sucursales()->sync([$this->sucursal->id]);
        $datos = $this->actingAs($limitado)->getJson("/productos/{$producto->id}/historial")->assertOk()->json();
        $this->assertSame(['Principal'], collect($datos['compras'])->pluck('sucursal')->all());
        $this->assertEqualsWithDelta(5, $datos['compras'][0]['cantidad'], 0.001);
        $this->assertSame(['Principal'], collect($datos['ventas'])->pluck('sucursal')->all());
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
