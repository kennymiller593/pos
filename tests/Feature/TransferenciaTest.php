<?php

namespace Tests\Feature;

use App\Models\CapaCosto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\Transferencia;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class TransferenciaTest extends TestCase
{
    use CreaEscenarioPos;

    private Sucursal $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->destino = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Sucursal Destino',
            'activo' => true,
        ]);
    }

    private function stockEn(string $productoId, string $sucursalId): float
    {
        return (float) Stock::where('producto_id', $productoId)->where('sucursal_id', $sucursalId)->value('cantidad');
    }

    /** Crea un lote con stock (capa ligada) en la sucursal principal. */
    private function darStockConLote(Producto $producto, string $numeroLote, int $venceEnDias, float $cantidad, float $costo): Lote
    {
        $lote = Lote::create([
            'empresa_id' => $this->empresa->id,
            'producto_id' => $producto->id,
            'sucursal_id' => $this->sucursal->id,
            'numero_lote' => $numeroLote,
            'fecha_vencimiento' => now()->addDays($venceEnDias)->toDateString(),
        ]);
        $this->darStock($producto, $cantidad, $costo)->update(['lote_id' => $lote->id]);

        return $lote;
    }

    public function test_los_lotes_viajan_a_la_sucursal_destino(): void
    {
        $producto = $this->crearProducto(atributos: ['controla_lote' => true]);
        $loteA = $this->darStockConLote($producto, 'A-1', venceEnDias: 5, cantidad: 10, costo: 2.00);
        $loteB = $this->darStockConLote($producto, 'B-1', venceEnDias: 60, cantidad: 10, costo: 2.50);

        // enviar 15: FEFO manda -> 10 del lote A (vence antes) + 5 del lote B
        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 15]],
        ])->assertSessionHas('success');

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $detalles = $transferencia->detalles()->get()->keyBy('lote_id');

        $this->assertCount(2, $detalles);
        $this->assertEqualsWithDelta(10, (float) $detalles[$loteA->id]->cantidad, 0.001);
        $this->assertEqualsWithDelta(5, (float) $detalles[$loteB->id]->cantidad, 0.001);

        // recibir: los lotes se replican en el destino con numero y vencimiento
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('success');

        $loteDestinoA = Lote::where('sucursal_id', $this->destino->id)->where('numero_lote', 'A-1')->firstOrFail();
        $this->assertSame(
            $loteA->fecha_vencimiento->toDateString(),
            $loteDestinoA->fecha_vencimiento->toDateString()
        );

        // cada lote llega como capa propia con SU costo (no el promedio)
        $capas = CapaCosto::where('producto_id', $producto->id)
            ->where('sucursal_id', $this->destino->id)
            ->get()
            ->keyBy(fn ($c) => Lote::find($c->lote_id)->numero_lote);

        $this->assertEqualsWithDelta(2.00, (float) $capas['A-1']->costo_unitario, 0.000001);
        $this->assertEqualsWithDelta(10, (float) $capas['A-1']->cantidad_restante, 0.001);
        $this->assertEqualsWithDelta(2.50, (float) $capas['B-1']->costo_unitario, 0.000001);
        $this->assertEqualsWithDelta(5, (float) $capas['B-1']->cantidad_restante, 0.001);
    }

    public function test_anular_devuelve_los_lotes_al_origen(): void
    {
        $producto = $this->crearProducto(atributos: ['controla_lote' => true]);
        $lote = $this->darStockConLote($producto, 'C-1', venceEnDias: 30, cantidad: 8, costo: 3.00);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 8]],
        ])->assertSessionHas('success');

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/anular")->assertSessionHas('success');

        // el stock vuelve al origen ligado a su mismo lote
        $this->assertEqualsWithDelta(8, $this->stockEn($producto->id, $this->sucursal->id), 0.001);
        $capaDevuelta = CapaCosto::where('producto_id', $producto->id)
            ->where('sucursal_id', $this->sucursal->id)
            ->where('cantidad_restante', '>', 0)
            ->firstOrFail();
        $this->assertSame($lote->id, $capaDevuelta->lote_id);
        $this->assertEqualsWithDelta(3.00, (float) $capaDevuelta->costo_unitario, 0.000001);
    }

    public function test_enviar_y_recibir_preserva_stock_y_costo_fifo(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 9, 2.00, diasAtras: 10);
        $this->darStock($producto, 91, 2.20, diasAtras: 2);

        // enviar 11 unidades (cruza capas: 9@2.00 + 2@2.20 -> promedio 2.036364)
        $respuesta = $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => 'Reposición',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 11]],
        ]);

        $respuesta->assertRedirect(route('transferencias.index'))->assertSessionHas('success');

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertSame('en_transito', $transferencia->estado);

        // el origen ya no tiene esas 11 unidades; el destino aun no las recibe
        $this->assertEqualsWithDelta(89, $this->stockEn($producto->id, $this->sucursal->id), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockEn($producto->id, $this->destino->id), 0.001);

        $salida = MovimientoInventario::where('referencia_id', $transferencia->id)->where('tipo', 'transferencia_salida')->firstOrFail();
        $this->assertEqualsWithDelta(2.036364, (float) $salida->costo_unitario, 0.000001);

        // recibir
        $this->actingAs($this->admin)
            ->post("/transferencias/{$transferencia->id}/recibir")
            ->assertSessionHas('success');

        $transferencia->refresh();
        $this->assertSame('recibida', $transferencia->estado);
        $this->assertNotNull($transferencia->recibida_en);

        // stock y capa en destino con el costo promedio que salio del origen
        $this->assertEqualsWithDelta(11, $this->stockEn($producto->id, $this->destino->id), 0.001);

        $capaDestino = CapaCosto::where('producto_id', $producto->id)->where('sucursal_id', $this->destino->id)->firstOrFail();
        $this->assertEqualsWithDelta(2.036364, (float) $capaDestino->costo_unitario, 0.000001);
        $this->assertEqualsWithDelta(11, (float) $capaDestino->cantidad_restante, 0.001);

        $this->assertSame(1, MovimientoInventario::where('referencia_id', $transferencia->id)->where('tipo', 'transferencia_entrada')->count());
    }

    public function test_no_se_recibe_dos_veces(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 20, 2.00);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5]],
        ]);

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir");
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('error');

        // el stock del destino no se duplico
        $this->assertEqualsWithDelta(5, $this->stockEn($producto->id, $this->destino->id), 0.001);
    }

    public function test_stock_insuficiente_en_origen_es_bloqueado(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 3, 2.00);

        $respuesta = $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 10]],
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, Transferencia::where('empresa_id', $this->empresa->id)->count());
        $this->assertEqualsWithDelta(3, $this->stockEn($producto->id, $this->sucursal->id), 0.001);
    }

    public function test_anular_en_transito_devuelve_el_stock_al_origen(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 20, 2.50);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 8]],
        ]);

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $this->assertEqualsWithDelta(12, $this->stockEn($producto->id, $this->sucursal->id), 0.001);

        $this->actingAs($this->admin)
            ->post("/transferencias/{$transferencia->id}/anular")
            ->assertSessionHas('success');

        $transferencia->refresh();
        $this->assertSame('anulada', $transferencia->estado);
        $this->assertEqualsWithDelta(20, $this->stockEn($producto->id, $this->sucursal->id), 0.001);
        $this->assertEqualsWithDelta(0, $this->stockEn($producto->id, $this->destino->id), 0.001);

        // una anulada ya no se puede recibir
        $this->actingAs($this->admin)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('error');
    }

    public function test_un_cajero_no_puede_anular(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 20, 2.00);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $this->destino->id,
            'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 5]],
        ]);

        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->post("/transferencias/{$transferencia->id}/anular")->assertSessionHas('error');
        $this->assertSame('en_transito', $transferencia->fresh()->estado);
    }
}
