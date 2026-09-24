<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\DetalleConsumoCapa;
use App\Models\MovimientoInventario;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class AnulacionTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function venderYObtenerComprobante(): Comprobante
    {
        $producto = $this->crearProducto(precio: 2.00);
        $this->darStock($producto, 20, 1.20);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 5);

        return Comprobante::where('empresa_id', $this->empresa->id)->firstOrFail();
    }

    public function test_anular_repone_stock_capas_y_retira_pagos(): void
    {
        $comprobante = $this->venderYObtenerComprobante();
        $producto = $comprobante->detalles()->first()->producto;

        $respuesta = $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", [
            'motivo' => 'Error de digitación',
        ]);

        $respuesta->assertSessionHas('success');

        $comprobante->refresh();
        $this->assertSame('anulado', $comprobante->estado);
        $this->assertSame('Error de digitación', $comprobante->motivo_anulacion);
        $this->assertSame($this->admin->id, $comprobante->anulado_por);

        $this->assertEqualsWithDelta(20, $this->stockDe($producto), 0.001);
        $this->assertSame(0, $comprobante->pagos()->count());
        $this->assertSame(0, DetalleConsumoCapa::where('detalle_id', $comprobante->detalles()->first()->id)->count());
        $this->assertSame(1, MovimientoInventario::where('producto_id', $producto->id)->where('tipo', 'devolucion')->count());
    }

    public function test_no_se_puede_anular_dos_veces(): void
    {
        $comprobante = $this->venderYObtenerComprobante();

        $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Primera']);
        $respuesta = $this->actingAs($this->admin)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Segunda']);

        $respuesta->assertSessionHas('error');
        $this->assertSame('Primera', $comprobante->fresh()->motivo_anulacion);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $comprobante = $this->venderYObtenerComprobante();

        $respuesta = $this->actingAs($this->admin)
            ->from('/comprobantes')
            ->post("/comprobantes/{$comprobante->id}/anular", []);

        $respuesta->assertSessionHasErrors('motivo');
        $this->assertSame('emitido', $comprobante->fresh()->estado);
    }

    public function test_un_cajero_no_puede_anular(): void
    {
        $comprobante = $this->venderYObtenerComprobante();
        $cajero = $this->crearUsuario('cajero', 'cajero' . random_int(1000, 9999) . '@test.local');

        $respuesta = $this->actingAs($cajero)->post("/comprobantes/{$comprobante->id}/anular", ['motivo' => 'Intento']);

        $respuesta->assertSessionHas('error');
        $this->assertSame('emitido', $comprobante->fresh()->estado);
    }

    public function test_anular_credito_sin_cobros_elimina_la_deuda(): void
    {
        $producto = $this->crearProducto(precio: 30.00);
        $this->darStock($producto, 10, 20.00);
        $this->abrirCaja();
        $cliente = $this->crearCliente(limiteCredito: 100);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [],
        ]);

        $cuenta = CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/comprobantes/{$cuenta->comprobante_id}/anular", ['motivo' => 'Cliente se arrepintió'])
            ->assertSessionHas('success');

        $this->assertNull(CuentaPorCobrar::find($cuenta->id));
    }

    public function test_no_se_anula_credito_con_cobros_registrados(): void
    {
        $producto = $this->crearProducto(precio: 30.00);
        $this->darStock($producto, 10, 20.00);
        $apertura = $this->abrirCaja();
        $cliente = $this->crearCliente(limiteCredito: 100);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1]],
            'pagos' => [],
        ]);

        $cuenta = CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();
        Cobro::create([
            'empresa_id' => $this->empresa->id,
            'cuenta_id' => $cuenta->id,
            'apertura_id' => $apertura->id,
            'usuario_id' => $this->admin->id,
            'medio_pago_codigo' => 'efectivo',
            'monto' => 10,
        ]);
        $cuenta->update(['monto_pagado' => 10, 'estado' => 'parcial']);

        $respuesta = $this->actingAs($this->admin)
            ->post("/comprobantes/{$cuenta->comprobante_id}/anular", ['motivo' => 'Intento']);

        $respuesta->assertSessionHas('error');
        $this->assertSame('emitido', $cuenta->comprobante->fresh()->estado);
    }
}
