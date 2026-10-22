<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\MovimientoInventario;
use App\Models\Proveedor;
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
