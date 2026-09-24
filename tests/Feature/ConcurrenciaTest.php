<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CapaCosto;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\Stock;
use App\Models\Sucursal;
use App\Models\Transferencia;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/**
 * Las carreras reales no se pueden reproducir dentro de la transacción del
 * test; aquí se prueban las defensas que sí son observables: el consumo FIFO
 * incompleto aborta la venta, las restricciones de la BD y las relecturas.
 */
class ConcurrenciaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_si_las_capas_no_alcanzan_la_venta_se_aborta_en_vez_de_salir_a_costo_cero(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        // simula la carrera: otra venta ya consumio las capas pero el stock aun dice 5
        CapaCosto::where('producto_id', $producto->id)->update(['cantidad_restante' => 2]);
        $this->abrirCaja();

        $this->venderContado($producto->presentaciones->first(), 5)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'stock cambió'));

        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(5.0, $this->stockDe($producto)); // nada se descontó (rollback)
    }

    public function test_la_base_no_admite_stock_negativo(): void
    {
        $producto = $this->crearProducto();
        $this->darStock($producto, 1, 1.00);

        $this->expectException(QueryException::class);
        Stock::where('producto_id', $producto->id)->decrement('cantidad', 2);
    }

    public function test_un_usuario_no_puede_tener_dos_cajas_abiertas_ni_por_la_base(): void
    {
        $segunda = Caja::create([
            'empresa_id' => $this->empresa->id, 'sucursal_id' => $this->sucursal->id, 'nombre' => 'Caja 2', 'activo' => true,
        ]);
        $this->abrirCaja();

        // el controlador lo rechaza antes...
        $this->actingAs($this->admin)->post('/caja/abrir', ['caja_id' => $segunda->id, 'monto_inicial' => 0])
            ->assertSessionHas('error');

        // ...y la BD tambien, por si dos clics llegan a la vez
        $this->expectException(UniqueConstraintViolationException::class);
        AperturaCaja::create([
            'empresa_id' => $this->empresa->id, 'caja_id' => $segunda->id, 'usuario_id' => $this->admin->id, 'monto_inicial' => 0,
        ]);
    }

    public function test_vender_con_la_caja_ya_cerrada_se_rechaza(): void
    {
        $producto = $this->crearProducto(precio: 10.00);
        $this->darStock($producto, 5, 4.00);
        $apertura = $this->abrirCaja();

        // la venta valida la apertura al inicio; si otro proceso la cierra justo antes de
        // la transaccion, la relectura con bloqueo lo detecta. Se simula cerrandola.
        $apertura->update(['cerrada_en' => now()]);

        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('error');
        $this->assertSame(0, Comprobante::where('empresa_id', $this->empresa->id)->count());
    }

    public function test_solo_un_usuario_de_la_sucursal_destino_recibe_la_transferencia(): void
    {
        $destino = Sucursal::create(['empresa_id' => $this->empresa->id, 'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'activo' => true]);
        $producto = $this->crearProducto();
        $this->darStock($producto, 10, 2.00);

        $this->actingAs($this->admin)->post('/transferencias', [
            'sucursal_destino_id' => $destino->id, 'observacion' => null,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 3]],
        ]);
        $transferencia = Transferencia::where('empresa_id', $this->empresa->id)->firstOrFail();

        // almacenero asignado solo a la sucursal de origen
        $almacenero = $this->crearUsuario('almacenero', 'alm'.random_int(10000, 99999).'@test.local');
        $almacenero->sucursales()->sync([$this->sucursal->id]);

        $this->actingAs($almacenero)->post("/transferencias/{$transferencia->id}/recibir")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'destino'));
        $this->assertSame('en_transito', $transferencia->fresh()->estado);

        $almacenero->sucursales()->sync([$destino->id]);
        $this->actingAs($almacenero)->post("/transferencias/{$transferencia->id}/recibir")->assertSessionHas('success');
        $this->assertSame('recibida', $transferencia->fresh()->estado);
    }

    public function test_un_cobro_mayor_al_saldo_releido_se_rechaza(): void
    {
        $cliente = $this->crearCliente(limiteCredito: 500);
        $producto = $this->crearProducto(precio: 50.00);
        $this->darStock($producto, 5, 4.00);
        $this->abrirCaja();

        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => $cliente->id, 'es_credito' => true,
            'items' => [['presentacion_id' => $producto->presentaciones->first()->id, 'cantidad' => 2]], 'pagos' => [],
        ])->assertSessionHas('success');
        $cuenta = CuentaPorCobrar::where('cliente_id', $cliente->id)->firstOrFail();

        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 60, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 40, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('success');
        $this->assertSame('pagado', $cuenta->fresh()->estado);
        $this->assertSame(100.0, (float) $cuenta->fresh()->monto_pagado);

        // ya saldada: un tercer cobro no pasa
        $this->actingAs($this->admin)->post("/cuentas-por-cobrar/{$cuenta->id}/cobrar", ['monto' => 1, 'medio_pago_codigo' => 'efectivo'])
            ->assertSessionHas('error');
    }
}
