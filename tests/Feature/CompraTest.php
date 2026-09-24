<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Compra;
use App\Models\MovimientoInventario;
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
