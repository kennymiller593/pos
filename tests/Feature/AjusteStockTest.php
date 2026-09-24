<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\MovimientoInventario;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class AjusteStockTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_entrada_crea_capa_de_costo_y_suma_stock(): void
    {
        $producto = $this->crearProducto();

        $respuesta = $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'direccion' => 'entrada',
            'cantidad' => 20,
            'costo_unitario' => 3.50,
        ]);

        $respuesta->assertSessionHas('success');
        $this->assertEqualsWithDelta(20, $this->stockDe($producto), 0.001);

        $capa = CapaCosto::where('producto_id', $producto->id)->firstOrFail();
        $this->assertEqualsWithDelta(3.50, (float) $capa->costo_unitario, 0.001);
        $this->assertSame(1, MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'ajuste')->count());
    }

    public function test_salida_consume_fifo_y_registra_merma(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 20, 3.50);

        $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'direccion' => 'salida',
            'cantidad' => 5,
        ])->assertSessionHas('success');

        $this->assertEqualsWithDelta(15, $this->stockDe($producto), 0.001);
        $this->assertEqualsWithDelta(15, (float) CapaCosto::where('producto_id', $producto->id)->value('cantidad_restante'), 0.001);

        $merma = MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'merma')->firstOrFail();
        $this->assertEqualsWithDelta(3.50, (float) $merma->costo_unitario, 0.001);
    }

    public function test_salida_mayor_al_disponible_es_bloqueada(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 10, 2.00);

        $respuesta = $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'direccion' => 'salida',
            'cantidad' => 999,
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertEqualsWithDelta(10, $this->stockDe($producto), 0.001);
    }
}
