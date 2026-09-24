<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class LoteTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function comprar($presentacion, float $cantidad, float $costo, ?string $lote = null, ?string $vence = null): void
    {
        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => null,
            'serie_numero' => null,
            'fecha' => now()->toDateString(),
            'es_credito' => false,
            'items' => [[
                'presentacion_id' => $presentacion->id,
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'numero_lote' => $lote,
                'fecha_vencimiento' => $vence,
            ]],
        ]);
    }

    public function test_la_compra_exige_lote_cuando_el_producto_lo_controla(): void
    {
        $producto = $this->crearProducto(precio: 10.00, atributos: ['controla_lote' => true]);
        $unidad = $producto->presentaciones->first();

        // sin lote -> rechazada
        $this->comprar($unidad, 5, 4.00);
        $this->assertEqualsWithDelta(0, $this->stockDe($producto), 0.001);

        // con lote -> registrada y enlazada en capa, detalle y kardex
        $this->comprar($unidad, 5, 4.00, 'L-001', now()->addMonths(6)->toDateString());

        $lote = Lote::where('producto_id', $producto->id)->where('numero_lote', 'L-001')->firstOrFail();
        $this->assertEqualsWithDelta(5, $this->stockDe($producto), 0.001);
        $this->assertSame($lote->id, CapaCosto::where('producto_id', $producto->id)->value('lote_id'));
        $this->assertSame($lote->id, MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'compra')->value('lote_id'));
    }

    public function test_la_venta_consume_primero_el_lote_que_vence_antes(): void
    {
        $producto = $this->crearProducto(precio: 10.00, atributos: ['controla_lote' => true]);
        $unidad = $producto->presentaciones->first();

        // lote LEJANO comprado primero (FIFO puro lo consumiria antes), vence en 60 dias
        $this->comprar($unidad, 10, 4.00, 'LEJANO', now()->addDays(60)->toDateString());
        // lote PRONTO comprado despues, pero vence en 5 dias
        $this->comprar($unidad, 10, 4.50, 'PRONTO', now()->addDays(5)->toDateString());

        $this->abrirCaja();
        $this->venderContado($unidad, 4);

        $capaLejano = CapaCosto::whereHas('lote', fn ($q) => $q->where('numero_lote', 'LEJANO'))->firstOrFail();
        $capaPronto = CapaCosto::whereHas('lote', fn ($q) => $q->where('numero_lote', 'PRONTO'))->firstOrFail();

        // FEFO: salieron 4 del lote que vence antes, el lejano quedo intacto
        $this->assertEqualsWithDelta(6, (float) $capaPronto->cantidad_restante, 0.001);
        $this->assertEqualsWithDelta(10, (float) $capaLejano->cantidad_restante, 0.001);

        // el detalle de la venta referencia al lote consumido
        $detalle = Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail()->detalles()->firstOrFail();
        $this->assertSame($capaPronto->lote_id, $detalle->lote_id);
    }

    public function test_ajuste_de_entrada_con_lote(): void
    {
        $producto = $this->crearProducto(atributos: ['controla_lote' => true]);

        // sin lote -> rechazado
        $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'direccion' => 'entrada', 'cantidad' => 10, 'costo_unitario' => 2.00,
        ])->assertSessionHas('error');

        // con lote -> capa enlazada
        $this->actingAs($this->admin)->post("/stock/{$producto->id}/ajustar", [
            'direccion' => 'entrada', 'cantidad' => 10, 'costo_unitario' => 2.00,
            'numero_lote' => 'AJ-01', 'fecha_vencimiento' => now()->addMonths(3)->toDateString(),
        ])->assertSessionHas('success');

        $lote = Lote::where('producto_id', $producto->id)->where('numero_lote', 'AJ-01')->firstOrFail();
        $this->assertSame($lote->id, CapaCosto::where('producto_id', $producto->id)->value('lote_id'));
    }

    public function test_el_kardex_lista_los_lotes_activos_y_excluye_agotados(): void
    {
        $producto = $this->crearProducto(precio: 10.00, atributos: ['controla_lote' => true]);
        $unidad = $producto->presentaciones->first();

        $this->comprar($unidad, 3, 4.00, 'CHICO', now()->addDays(10)->toDateString());
        $this->comprar($unidad, 10, 4.00, 'GRANDE', now()->addDays(90)->toDateString());

        // consumir todo el lote CHICO (FEFO) -> deja de aparecer
        $this->abrirCaja();
        $this->venderContado($unidad, 3);

        $respuesta = $this->actingAs($this->admin)->getJson("/stock/{$producto->id}/kardex");

        $respuesta->assertOk();
        $lotes = collect($respuesta->json('lotes'));
        $this->assertCount(1, $lotes);
        $this->assertSame('GRANDE', $lotes[0]['numero_lote']);
        $this->assertEqualsWithDelta(10, $lotes[0]['restante'], 0.001);
    }
}
