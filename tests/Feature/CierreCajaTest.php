<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\CierreCajaMedio;
use App\Models\Producto;
use App\Services\CajaService;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

/** Cuadre por medio de pago, conteo por denominación y ticket de cierre. */
class CierreCajaTest extends TestCase
{
    use CreaEscenarioPos;

    private AperturaCaja $apertura;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
        $this->producto = $this->crearProducto(precio: 10.00);
        $this->darStock($this->producto, 100, 4.00);
        $this->apertura = $this->abrirCaja(montoInicial: 100);
    }

    private function vender(array $pagos, float $cantidad): void
    {
        $this->actingAs($this->admin)->post('/pos/ventas', [
            'tipo_comprobante_codigo' => '00', 'cliente_id' => null, 'es_credito' => false,
            'items' => [['presentacion_id' => $this->producto->presentaciones->first()->id, 'cantidad' => $cantidad]],
            'pagos' => $pagos,
        ])->assertSessionHas('success');
    }

    /** Ventas: 50 en efectivo, 30 por Yape, 20 mixtos (10 efectivo + 10 tarjeta); egreso de 15 en efectivo. */
    private function movimientosDelTurno(): void
    {
        $this->vender([['medio_pago_codigo' => 'efectivo', 'monto' => 50, 'referencia' => null]], 5);
        $this->vender([['medio_pago_codigo' => 'yape', 'monto' => 30, 'referencia' => 'OP-1']], 3);
        $this->vender([
            ['medio_pago_codigo' => 'efectivo', 'monto' => 10, 'referencia' => null],
            ['medio_pago_codigo' => 'tarjeta', 'monto' => 10, 'referencia' => 'VOUCHER-9'],
        ], 2);
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Movilidad', 'monto' => 15])
            ->assertSessionHas('success');
    }

    public function test_el_resumen_por_medio_separa_efectivo_yape_y_tarjeta(): void
    {
        $this->movimientosDelTurno();

        $medios = collect(app(CajaService::class)->resumenPorMedio($this->apertura->fresh()))->keyBy('codigo');

        $this->assertSame('efectivo', collect(app(CajaService::class)->resumenPorMedio($this->apertura->fresh()))->first()['codigo']);
        $this->assertEqualsWithDelta(100 + 50 + 10 - 15, $medios['efectivo']['esperado'], 0.001);
        $this->assertEqualsWithDelta(30, $medios['yape']['esperado'], 0.001);
        $this->assertEqualsWithDelta(10, $medios['tarjeta']['esperado'], 0.001);
        $this->assertFalse($medios->has('plin')); // sin movimiento no aparece

        // coincide con el esperado de efectivo de siempre
        $this->assertEqualsWithDelta(app(CajaService::class)->resumen($this->apertura->fresh())['esperado'], $medios['efectivo']['esperado'], 0.001);

        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($p) => $p
            ->has('apertura.medios', 3)
            ->has('denominaciones', 11));
    }

    public function test_cierre_con_conteo_por_billetes_y_yape_faltante(): void
    {
        $this->movimientosDelTurno();

        // efectivo esperado 145: 1x100 + 2x20 + 1x5 = 145 (cuadra); yape declarado 25 de 30 esperados; tarjeta sin verificar
        $this->actingAs($this->admin)->post('/caja/cerrar', [
            'conteo' => ['100' => 1, '20' => 2, '5' => 1, '0.10' => 0],
            'declarados' => ['yape' => 25, 'tarjeta' => null],
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Faltan S/ 5.00 en Yape'))
            ->assertSessionHas('ticket');

        $this->apertura->refresh();
        $this->assertNotNull($this->apertura->cerrada_en);
        $this->assertEqualsWithDelta(145, (float) $this->apertura->monto_cierre, 0.001);
        $this->assertEqualsCanonicalizing(['100' => 1, '20' => 2, '5' => 1], $this->apertura->conteo_efectivo);

        $cierres = CierreCajaMedio::where('apertura_id', $this->apertura->id)->get()->keyBy('medio_pago_codigo');
        $this->assertCount(3, $cierres);
        $this->assertEqualsWithDelta(0, (float) $cierres['efectivo']->diferencia, 0.001);
        $this->assertEqualsWithDelta(-5, (float) $cierres['yape']->diferencia, 0.001);
        $this->assertNull($cierres['tarjeta']->declarado);
        $this->assertEqualsWithDelta(0, (float) $cierres['tarjeta']->diferencia, 0.001);

        $this->assertDatabaseHas('auditoria', ['entidad_id' => $this->apertura->id, 'accion' => 'caja.cierre_con_diferencia']);

        // el historial trae el cuadre por medio y la diferencia total
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($p) => $p
            ->has('historial.data.0.medios', 3)
            ->where('historial.data.0.diferencia_total', fn ($v) => abs($v - (-5)) < 0.001));
    }

    public function test_caja_cuadrada_no_se_audita_y_el_ticket_de_cierre_se_genera(): void
    {
        $this->movimientosDelTurno();

        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 145, 'declarados' => ['yape' => 30, 'tarjeta' => 10]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'cuadrada'));

        $this->assertDatabaseMissing('auditoria', ['entidad_id' => $this->apertura->id, 'accion' => 'caja.cierre_con_diferencia']);

        $pdf = $this->actingAs($this->admin)->get("/caja/turnos/{$this->apertura->id}/ticket");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // otro cajero no puede ver el cierre ajeno
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->get("/caja/turnos/{$this->apertura->id}/ticket")->assertForbidden();
    }
}
