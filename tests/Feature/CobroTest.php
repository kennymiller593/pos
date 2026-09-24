<?php

namespace Tests\Feature;

use App\Models\CuentaPorCobrar;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CobroTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function crearDeudaDe60(): CuentaPorCobrar
    {
        $producto = $this->crearProducto(precio: 30.00);
        $this->darStock($producto, 10, 20.00);
        $this->abrirCaja(100);
        $cliente = $this->crearCliente(limiteCredito: 100);

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00',
            'cliente_id' => $cliente->id,
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]],
            'pagos' => [],
        ]);

        return CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();
    }

    public function test_cobro_parcial_y_luego_total(): void
    {
        $cuenta = $this->crearDeudaDe60();

        // cobro parcial de 20 en efectivo
        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 20, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame('parcial', $cuenta->estado);
        $this->assertEqualsWithDelta(20, (float) $cuenta->monto_pagado, 0.001);

        // cobro del saldo
        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 40, 'medio_pago_codigo' => 'yape', 'referencia' => 'YP-1'])
            ->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame('pagado', $cuenta->estado);
        $this->assertSame(2, $cuenta->cobros()->count());

        // no se puede cobrar una cuenta pagada
        $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 10, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('error');
    }

    public function test_el_cobro_no_puede_exceder_el_saldo(): void
    {
        $cuenta = $this->crearDeudaDe60();

        $respuesta = $this->actingAs($this->admin)
            ->from('/cuentas-por-cobrar')
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 99, 'medio_pago_codigo' => 'efectivo']);

        $respuesta->assertSessionHasErrors('monto');
        $this->assertEqualsWithDelta(0, (float) $cuenta->fresh()->monto_pagado, 0.001);
    }

    public function test_cobrar_requiere_caja_abierta(): void
    {
        $cuenta = $this->crearDeudaDe60();

        // cerrar el turno antes de intentar cobrar
        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 100]);

        $respuesta = $this->actingAs($this->admin)
            ->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 20, 'medio_pago_codigo' => 'efectivo']);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, $cuenta->cobros()->count());
    }
}
