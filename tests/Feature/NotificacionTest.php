<?php

namespace Tests\Feature;

use App\Models\Lote;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class NotificacionTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_la_campanita_junta_stock_bajo_lotes_y_deudas(): void
    {
        // producto sin stock -> alerta de stock bajo
        $sinStock = $this->crearProducto(precio: 5.00);

        // producto con stock cuyo lote vence en 10 dias -> alerta de vencimiento
        $conLote = $this->crearProducto(precio: 8.00);
        $capa = $this->darStock($conLote, 10, 3.00);
        $lote = Lote::create([
            'empresa_id' => $this->empresa->id,
            'producto_id' => $conLote->id,
            'sucursal_id' => $this->sucursal->id,
            'numero_lote' => 'L-VENCE',
            'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        ]);
        $capa->update(['lote_id' => $lote->id]);

        // venta al credito -> cuenta por cobrar pendiente de S/ 8.00
        $cliente = $this->crearCliente(limiteCredito: 100);
        $this->abrirCaja();
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $conLote->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [],
        ])->assertSessionHas('success');

        $respuesta = $this->actingAs($this->admin)->getJson('/notificaciones');

        $respuesta->assertOk()->assertJson(fn ($json) => $json
            ->has('items', 3)
            ->where('items.0.clave', 'stock_bajo')
            ->where('items.0.cantidad', 1)
            ->where('items.1.clave', 'lotes_por_vencer')
            ->where('items.1.cantidad', 1)
            ->where('items.2.clave', 'por_cobrar')
            ->where('items.2.cantidad', 1)
            ->where('total', 3)
        );

        // sin usar $sinStock en nada mas: solo existe para disparar la alerta
        $this->assertTrue($sinStock->exists);
    }

    public function test_sin_pendientes_no_hay_alertas(): void
    {
        $respuesta = $this->actingAs($this->admin)->getJson('/notificaciones');

        $respuesta->assertOk()->assertJson(['items' => [], 'total' => 0]);
    }
}
