<?php

namespace Tests\Feature;

use App\Models\CuentaPorPagar;
use App\Models\Proveedor;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CuentaPorPagarTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    private function comprarACredito(float $costo = 100.0, ?string $vence = null): CuentaPorPagar
    {
        $proveedor = Proveedor::create([
            'empresa_id' => $this->empresa->id,
            'ruc' => '20'.random_int(100000000, 999999999),
            'razon_social' => 'Proveedor Test '.random_int(1000, 9999),
        ]);
        $producto = $this->crearProducto();

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante_codigo' => null,
            'serie_numero' => null,
            'fecha' => now()->toDateString(),
            'es_credito' => true,
            'fecha_vencimiento' => $vence,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1, 'costo_unitario' => $costo]],
        ])->assertSessionHas('success');

        return CuentaPorPagar::where('empresa_id', $this->empresa->id)->latest('id')->firstOrFail();
    }

    public function test_la_compra_a_credito_genera_la_deuda(): void
    {
        $vence = now()->addDays(15)->toDateString();
        $cuenta = $this->comprarACredito(150.0, $vence);

        $this->assertEqualsWithDelta(150.0, (float) $cuenta->monto_total, 0.001);
        $this->assertSame('pendiente', $cuenta->estado);
        $this->assertSame($vence, $cuenta->fecha_vencimiento->toDateString());
    }

    public function test_compra_a_credito_sin_proveedor_es_rechazada(): void
    {
        $producto = $this->crearProducto();

        $this->actingAs($this->admin)->post('/compras', [
            'proveedor_id' => null,
            'tipo_comprobante_codigo' => null,
            'serie_numero' => null,
            'fecha' => now()->toDateString(),
            'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 1, 'costo_unitario' => 10]],
        ])->assertSessionHasErrors('proveedor_id');
    }

    public function test_pago_parcial_y_total_con_efectivo_desde_caja(): void
    {
        $cuenta = $this->comprarACredito(100.0);
        $this->abrirCaja(200); // hay 200 en el cajon

        // pago parcial de 60 en efectivo
        $this->actingAs($this->admin)->post("/cuentas-por-pagar/{$cuenta->id}/pagar", [
            'monto' => 60,
            'medio_pago_codigo' => 'efectivo',
            'referencia' => null,
        ])->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame('parcial', $cuenta->estado);
        $this->assertEqualsWithDelta(60.0, (float) $cuenta->monto_pagado, 0.001);

        // el arqueo refleja la salida de efectivo: 200 - 60 = 140
        $apertura = app(\App\Services\CajaService::class)->aperturaDe($this->admin);
        $this->assertEqualsWithDelta(140.0, app(\App\Services\CajaService::class)->resumen($apertura)['esperado'], 0.001);

        // pagar mas que el saldo es rechazado
        $this->actingAs($this->admin)->post("/cuentas-por-pagar/{$cuenta->id}/pagar", [
            'monto' => 50,
            'medio_pago_codigo' => 'efectivo',
            'referencia' => null,
        ])->assertSessionHasErrors('monto');

        // saldar los 40 restantes por transferencia (no toca la caja)
        $this->actingAs($this->admin)->post("/cuentas-por-pagar/{$cuenta->id}/pagar", [
            'monto' => 40,
            'medio_pago_codigo' => 'transferencia',
            'referencia' => 'OP-123',
        ])->assertSessionHas('success');

        $cuenta->refresh();
        $this->assertSame('pagado', $cuenta->estado);
        $this->assertEqualsWithDelta(140.0, app(\App\Services\CajaService::class)->resumen($apertura)['esperado'], 0.001);
    }

    public function test_pagar_en_efectivo_sin_caja_abierta_es_bloqueado(): void
    {
        $cuenta = $this->comprarACredito(50.0);

        $this->actingAs($this->admin)->post("/cuentas-por-pagar/{$cuenta->id}/pagar", [
            'monto' => 50,
            'medio_pago_codigo' => 'efectivo',
            'referencia' => null,
        ])->assertSessionHas('error');

        $this->assertSame('pendiente', $cuenta->fresh()->estado);
    }

    public function test_no_se_puede_pagar_cuentas_de_otra_empresa(): void
    {
        $cuenta = $this->comprarACredito(50.0);

        // admin de otra empresa
        $this->crearEscenarioBase();

        $this->actingAs($this->admin)->post("/cuentas-por-pagar/{$cuenta->id}/pagar", [
            'monto' => 50,
            'medio_pago_codigo' => 'transferencia',
            'referencia' => null,
        ])->assertForbidden();
    }
}
