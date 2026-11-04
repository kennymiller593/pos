<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
use App\Services\CajaService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CompraTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_compra_crea_stock_capas_fifo_y_kardex(): void
    {
        $producto = $this->crearProducto(precio: 13.00);
        $unidad = $producto->presentaciones->first();
        $caja12 = $this->agregarPresentacion($producto, 'Caja x12', 12, 145.00);

        // 2 cajas x12 a S/96 (costo base 8.00) + 5 unidades a S/8.50
        $respuesta = $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => '01',
            'serie_numero' => 'F001-000777',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [
                ['presentacion_id' => $caja12->id, 'cantidad' => 2, 'costo_unitario' => 96.00],
                ['presentacion_id' => $unidad->id, 'cantidad' => 5, 'costo_unitario' => 8.50],
            ],
        ]);

        $respuesta->assertRedirect(route('compras.index'))->assertSessionHas('success');

        $compra = Compra::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(234.50, (float) $compra->total, 0.001);
        $this->assertSame(2, $compra->detalles()->count());

        // stock en unidades base: 2x12 + 5 = 29
        $this->assertEqualsWithDelta(29, $this->stockDe($producto), 0.001);

        // capas FIFO con el costo convertido a unidad base
        $costos = CapaCosto::where('producto_id', $producto->id)
            ->orderBy('costo_unitario')->pluck('costo_unitario')
            ->map(fn ($c) => (float) $c)->all();
        $this->assertEqualsWithDelta(8.00, $costos[0], 0.001);
        $this->assertEqualsWithDelta(8.50, $costos[1], 0.001);

        $this->assertSame(2, MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'compra')->count());

        // el listado incluye los productos comprados (detalle expandible)
        $listado = $this->actingAs($this->admin)->get('/compras');
        $listado->assertOk();
        $this->assertStringContainsString($producto->nombre, $listado->getContent());
        $this->assertStringContainsString('Caja x12', $listado->getContent());
    }

    public function test_al_registrar_otra_compra_sale_de_referencia_el_ultimo_costo_pagado(): void
    {
        $producto = $this->crearProducto(precio: 13.00);
        $unidad = $producto->presentaciones->first();
        $caja12 = $this->agregarPresentacion($producto, 'Caja x12', 12, 145.00);
        $compra = fn (array $items, string $fecha) => $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(1000, 9999), 'fecha' => $fecha, 'es_credito' => false, 'items' => $items,
        ])->assertSessionHas('success');

        // sin compras previas no hay referencia
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->where('productos.0.presentaciones.0.ultimo_costo', null));

        $compra([['presentacion_id' => $unidad->id, 'cantidad' => 5, 'costo_unitario' => 8.50]], now()->subDays(3)->toDateString());
        $compra([['presentacion_id' => $unidad->id, 'cantidad' => 2, 'costo_unitario' => 9.00], ['presentacion_id' => $caja12->id, 'cantidad' => 1, 'costo_unitario' => 96.00]], now()->toDateString());

        // manda la compra más reciente, por presentación
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p
            ->where('productos.0.presentaciones.0.id', $unidad->id)->where('productos.0.presentaciones.0.ultimo_costo', fn ($v) => (float) $v === 9.0)
            ->where('productos.0.presentaciones.1.ultimo_costo', fn ($v) => (float) $v === 96.0));

        // otra empresa no ve estos costos
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->missing('productos.0.presentaciones.0.ultimo_costo'));
    }

    public function test_sin_compras_la_referencia_sale_de_la_ultima_capa_de_inventario(): void
    {
        // stock que entró sin compra (migración o ajuste): su costo es la referencia, llevado a cada presentación
        $producto = $this->crearProducto(precio: 13.00);
        $unidad = $producto->presentaciones->first();
        $caja12 = $this->agregarPresentacion($producto, 'Caja x12', 12, 145.00);
        $this->darStock($producto, 10, 7.00, diasAtras: 5);
        $this->darStock($producto, 4, 7.50);

        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p
            ->where('productos.0.presentaciones.0.id', $unidad->id)->where('productos.0.presentaciones.0.ultimo_costo', fn ($v) => (float) $v === 7.5)
            ->where('productos.0.presentaciones.1.id', $caja12->id)->where('productos.0.presentaciones.1.ultimo_costo', fn ($v) => (float) $v === 90.0));

        // una compra registrada manda sobre la capa
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-1', 'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 2, 'costo_unitario' => 8.00]],
        ])->assertSessionHas('success');
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn ($p) => $p->where('productos.0.presentaciones.0.ultimo_costo', fn ($v) => (float) $v === 8.0));
    }

    public function test_al_comprar_sugiere_los_lotes_vigentes_registrados_en_cualquier_sucursal(): void
    {
        $producto = $this->crearProducto(precio: 20.00, atributos: ['controla_lote' => true]);
        $otra = \App\Models\Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0002', 'nombre' => 'Tarapacá', 'activo' => true]);
        $lote = fn (string $numero, ?string $vence, string $sucursal) => \App\Models\Lote::create(['empresa_id' => $this->empresa->id, 'producto_id' => $producto->id, 'sucursal_id' => $sucursal, 'numero_lote' => $numero, 'fecha_vencimiento' => $vence]);
        $lote('L-VIEJO', now()->subDay()->toDateString(), $otra->id); // vencido: no se sugiere
        $lote('L-100', now()->addYear()->toDateString(), $this->sucursal->id);
        $lote('L-200', now()->addMonths(18)->toDateString(), $otra->id);

        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p
            ->has('productos.0.lotes_recientes', 2)
            ->where('productos.0.lotes_recientes.0.numero', 'L-200')
            ->where('productos.0.lotes_recientes.0.sucursal', 'Tarapacá')
            ->where('productos.0.lotes_recientes.0.vence', now()->addMonths(18)->toDateString())
            ->where('productos.0.lotes_recientes.1.numero', 'L-100'));

        // otra empresa no ve estos lotes
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->missing('productos.0.lotes_recientes.0'));
    }

    public function test_listado_filtra_por_fechas_y_proveedor(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $unidad = $producto->presentaciones->first();
        $proveedorA = Proveedor::create(['empresa_id' => $this->empresa->id, 'razon_social' => 'Proveedor Alfa '.random_int(1000, 9999), 'ruc' => '20'.random_int(100000000, 999999999)]);
        $proveedorB = Proveedor::create(['empresa_id' => $this->empresa->id, 'razon_social' => 'Proveedor Beta '.random_int(1000, 9999), 'ruc' => '20'.random_int(100000000, 999999999)]);

        foreach ([[$proveedorA, '2026-03-05', 'F001-000001'], [$proveedorB, '2026-03-20', 'F001-000002'], [$proveedorA, '2026-04-02', 'F001-000003']] as [$proveedor, $fecha, $numero]) {
            $this->actingAs($this->admin)->post('/compras', [
                'proveedor_id' => $proveedor->id,
                'tipo_comprobante_codigo' => '01',
                'serie_numero' => $numero,
                'fecha' => $fecha,
                'es_credito' => false,
                'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 1, 'costo_unitario' => 8.00]],
            ])->assertRedirect(route('compras.index'));
        }

        $documentos = fn (string $url) => collect($this->actingAs($this->admin)->get($url)->assertOk()
            ->viewData('page')['props']['compras']['data'])->pluck('serie_numero')->sort()->values()->all();

        $this->assertSame(['F001-000001', 'F001-000002', 'F001-000003'], $documentos('/compras'));
        $this->assertSame(['F001-000001', 'F001-000002'], $documentos('/compras?desde=2026-03-01&hasta=2026-03-31'));
        $this->assertSame(['F001-000003'], $documentos('/compras?desde=2026-04-01'));
        $this->assertSame(['F001-000001', 'F001-000003'], $documentos("/compras?proveedor={$proveedorA->id}"));
        $this->assertSame(['F001-000002'], $documentos("/compras?proveedor={$proveedorB->id}&desde=2026-03-01&hasta=2026-03-31"));
        // una fecha o proveedor mal formados no rompen ni filtran
        $this->assertSame(['F001-000001', 'F001-000002', 'F001-000003'], $documentos('/compras?desde=ayer&proveedor=xx'));

        // el select trae los proveedores de la empresa y los filtros aplicados vuelven a la página
        $props = $this->actingAs($this->admin)->get("/compras?proveedor={$proveedorA->id}&desde=2026-03-01")->viewData('page')['props'];
        $this->assertSame(['desde' => '2026-03-01', 'proveedor' => $proveedorA->id], $props['filtros']);
        $this->assertContains($proveedorA->razon_social, collect($props['proveedores'])->pluck('razon_social')->all());
    }

    public function test_listado_busca_compras_por_producto_lote_o_comprobante(): void
    {
        $zeta = $this->crearProducto(precio: 60.00, atributos: ['nombre' => 'ZETA Extra Large x FCO 250 ML.', 'controla_lote' => true]);
        $coronel = $this->crearProducto(precio: 40.00, atributos: ['nombre' => 'CORONEL 247 SC x FCO 500 ML.']);
        $compra = fn (string $numero, array $items) => $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '01', 'serie_numero' => $numero,
            'fecha' => now()->toDateString(), 'es_credito' => false, 'items' => $items,
        ])->assertRedirect(route('compras.index'));

        $compra('F001-000101', [
            ['presentacion_id' => $zeta->presentaciones->first()->id, 'cantidad' => 5, 'costo_unitario' => 48, 'numero_lote' => 'L-25874', 'fecha_vencimiento' => '2029-07-15'],
            ['presentacion_id' => $coronel->presentaciones->first()->id, 'cantidad' => 2, 'costo_unitario' => 35],
        ]);
        $compra('F001-000102', [['presentacion_id' => $coronel->presentaciones->first()->id, 'cantidad' => 3, 'costo_unitario' => 34]]);

        $buscar = fn (string $texto) => collect($this->actingAs($this->admin)->get('/compras?buscar='.urlencode($texto))->assertOk()
            ->viewData('page')['props']['compras']['data']);
        $documentos = fn (string $texto) => $buscar($texto)->pluck('serie_numero')->sort()->values()->all();

        // por producto: sin tildes ni mayúsculas, por palabras sueltas
        $this->assertSame(['F001-000101'], $documentos('zeta 250'));
        $this->assertSame(['F001-000101', 'F001-000102'], $documentos('córonel'));
        // por lote y por N° de comprobante
        $this->assertSame(['F001-000101'], $documentos('25874'));
        $this->assertSame(['F001-000102'], $documentos('f001-000102'));
        $this->assertSame([], $documentos('glifosato'));

        // en la compra se marca solo la línea del producto buscado
        $lineas = collect($buscar('zeta')->first()['detalles']);
        $this->assertSame([true, false], $lineas->sortByDesc('coincide')->pluck('coincide')->values()->all());
        $this->assertSame('L-25874', $lineas->firstWhere('coincide', true)['lote']['numero_lote']);

        // otra empresa no encuentra estas compras
        $this->crearEscenarioBase();
        $this->assertSame([], $documentos('zeta'));
    }

    public function test_descarga_de_compra_en_pdf(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $unidad = $producto->presentaciones->first();

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => null,
            'serie_numero' => 'F001-000123',
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 3, 'costo_unitario' => 7.00]],
        ]);

        $compra = Compra::where('empresa_id', $this->empresa->id)->firstOrFail();

        $respuesta = $this->actingAs($this->admin)->get("/compras/{$compra->id}/pdf");

        $respuesta->assertOk();
        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());

        // otra empresa no puede descargarla
        $this->crearEscenarioBase();
        $this->actingAs($this->admin)->get("/compras/{$compra->id}/pdf")->assertForbidden();
    }

    public function test_la_compra_al_contado_se_descuenta_de_la_caja_abierta(): void
    {
        $producto = $this->crearProducto(precio: 20.00);
        $unidad = $producto->presentaciones->first();
        $compra = fn (array $extra = []) => $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(1000, 9999), 'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 10, 'costo_unitario' => 8.00]], ...$extra,
        ]);
        $caja = app(CajaService::class);
        $comprasAntes = Compra::count();
        $cuentasAntes = CuentaPorPagar::count();

        // sin caja abierta no hay de dónde descontar
        $compra(['pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('error');
        $this->assertSame($comprasAntes, Compra::count());
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->where('caja.abierta', false));

        $apertura = $this->abrirCaja(50);
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p
            ->where('caja.abierta', true)->where('caja.efectivo', fn ($v) => (float) $v === 50.0)->has('mediosPago'));

        // la compra de 80 sale del cajón aunque solo haya 50: se avisa en pantalla, no se bloquea (como en el sistema anterior)
        $compra(['pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('success');
        $registrada = Compra::latest('creado_en')->first();
        $pago = $registrada->pagoCaja;
        $this->assertNotNull($pago);
        $this->assertSame($apertura->id, $pago->apertura_id);
        $this->assertEqualsWithDelta(80.0, (float) $pago->monto, 0.001);
        $this->assertEqualsWithDelta(-30.0, $caja->resumen($apertura)['esperado'], 0.001);
        $this->assertEqualsWithDelta(80.0, $caja->resumen($apertura)['egresos'], 0.001);
        $this->assertSame($cuentasAntes, CuentaPorPagar::count()); // no pasa por cuentas por pagar

        // y se ve en los movimientos del turno
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn (Assert $p) => $p
            ->where('apertura.movimientos.0.origen', 'compra')
            ->where('apertura.movimientos.0.tipo', 'egreso')
            ->where('apertura.movimientos.0.monto', fn ($v) => (float) $v === 80.0));

        // sin marcarlo, la caja no se toca
        $compra(['pagar_desde_caja' => false])->assertSessionHas('success');
        $this->assertEqualsWithDelta(-30.0, $caja->resumen($apertura)['esperado'], 0.001);

        // al anular con el turno aún abierto, el dinero vuelve al cajón
        $this->actingAs($this->admin)->post("/compras/{$registrada->id}/anular", ['motivo' => 'Error de registro'])->assertSessionHas('success');
        $this->assertNull($registrada->fresh()->pagoCaja);
        $this->assertEqualsWithDelta(50.0, $caja->resumen($apertura)['esperado'], 0.001);
    }

    public function test_pago_desde_caja_con_otro_medio_sin_medio_o_de_otra_sucursal(): void
    {
        $unidad = $this->crearProducto(precio: 20.00)->presentaciones->first();
        $apertura = $this->abrirCaja(100);
        $caja = app(CajaService::class);
        $compra = fn (array $extra = []) => $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-'.random_int(1000, 9999), 'fecha' => now()->toDateString(), 'es_credito' => false,
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 3, 'costo_unitario' => 10.00]], ...$extra,
        ]);

        // marcado sin decir con qué se pagó: no pasa
        $compra(['pagar_desde_caja' => true])->assertSessionHasErrors('medio_pago_codigo');

        // pagado por transferencia: queda en el cuadre de ese medio y el efectivo del cajón no cambia
        $compra(['pagar_desde_caja' => true, 'medio_pago_codigo' => 'transferencia', 'referencia_pago' => 'OP-55'])->assertSessionHas('success');
        $this->assertEqualsWithDelta(100.0, $caja->resumen($apertura)['esperado'], 0.001);
        $transferencia = collect($caja->resumenPorMedio($apertura))->firstWhere('codigo', 'transferencia');
        $this->assertEqualsWithDelta(30.0, $transferencia['pagos_proveedor'], 0.001);
        $this->assertSame('OP-55', Compra::latest('id')->first()->pagoCaja->referencia); // id uuid v7: el último creado

        // a crédito, lo de la caja se ignora: queda la deuda y ningún pago
        $proveedor = Proveedor::create(['empresa_id' => $this->empresa->id, 'razon_social' => 'Insumos SAC', 'ruc' => '20'.random_int(100000000, 999999999)]);
        $compra(['es_credito' => true, 'proveedor_id' => $proveedor->id, 'pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo'])->assertSessionHas('success');
        $this->assertNull(Compra::where('es_credito', true)->latest('id')->first()->pagoCaja);
        $this->assertEqualsWithDelta(100.0, $caja->resumen($apertura)['esperado'], 0.001);

    }

    public function test_no_se_descuenta_de_una_caja_de_otra_sucursal(): void
    {
        $unidad = $this->crearProducto(precio: 20.00)->presentaciones->first();
        $otra = \App\Models\Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0002', 'nombre' => 'Anexo', 'activo' => true]);
        $cajaOtra = \App\Models\Caja::create(['empresa_id' => $this->empresa->id, 'sucursal_id' => $otra->id, 'nombre' => 'Caja Anexo', 'activo' => true]);
        \App\Models\AperturaCaja::create(['empresa_id' => $this->empresa->id, 'caja_id' => $cajaOtra->id, 'usuario_id' => $this->admin->id, 'monto_inicial' => 300]);

        // la compra entra a la sucursal principal; la caja abierta es del anexo: no se descuenta de ahí
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-1', 'fecha' => now()->toDateString(), 'es_credito' => false,
            'pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo',
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 1, 'costo_unitario' => 10.00]],
        ])->assertSessionHas('error');
        $this->assertSame(0, \App\Models\PagoProveedor::where('empresa_id', $this->empresa->id)->whereNotNull('compra_id')->count()); // solo esta empresa: la BD local puede tener compras reales
        $this->actingAs($this->admin)->get('/compras/crear')->assertInertia(fn (Assert $p) => $p->where('caja.sucursal_id', $otra->id)->where('caja.nombre', 'Caja Anexo'));
    }

    public function test_no_se_anula_una_compra_descontada_de_una_caja_que_ya_cerro(): void
    {
        $unidad = $this->crearProducto(precio: 20.00)->presentaciones->first();
        $apertura = $this->abrirCaja(500);
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null, 'tipo_comprobante_codigo' => '00', 'serie_numero' => 'N-77', 'fecha' => now()->toDateString(), 'es_credito' => false,
            'pagar_desde_caja' => true, 'medio_pago_codigo' => 'efectivo',
            'items' => [['presentacion_id' => $unidad->id, 'cantidad' => 2, 'costo_unitario' => 10.00]],
        ])->assertSessionHas('success');
        $apertura->forceFill(['cerrada_en' => now()])->save();

        $compra = Compra::latest('creado_en')->first();
        $this->actingAs($this->admin)->post("/compras/{$compra->id}/anular", ['motivo' => 'Prueba'])->assertSessionHas('error');
        $this->assertSame('registrada', $compra->fresh()->estado);
        $this->assertNotNull($compra->fresh()->pagoCaja);
    }

    public function test_compra_sin_items_es_rechazada(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->from('/compras/crear')
            ->post('/compras', [
                'proveedor_id' => null,
                'tipo_comprobante_codigo' => null,
                'serie_numero' => null,
                'fecha' => now()->toDateString(),
                'es_credito' => false,
                'items' => [],
            ]);

        $respuesta->assertSessionHasErrors('items');
        $this->assertSame(0, Compra::where('empresa_id', $this->empresa->id)->count());
    }
}
