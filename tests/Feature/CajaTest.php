<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Sucursal;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class CajaTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_ciclo_completo_de_caja_con_arqueo(): void
    {
        // abrir con 100
        $this->actingAs($this->admin)
            ->post('/caja/abrir', ['caja_id' => $this->caja->id, 'monto_inicial' => 100])
            ->assertSessionHas('success');

        $apertura = AperturaCaja::whereNull('cerrada_en')->where('caja_id', $this->caja->id)->firstOrFail();

        // ingreso 50, egreso 30 -> esperado 120
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'ingreso', 'concepto' => 'Sencillo', 'monto' => 50]);
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Bolsas', 'monto' => 30]);
        $this->assertSame(2, $apertura->movimientos()->count());

        // cerrar contando 115 -> faltan 5
        $respuesta = $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 115]);
        $respuesta->assertSessionHas('success', fn ($mensaje) => str_contains($mensaje, 'Faltan'));

        $apertura->refresh();
        $this->assertNotNull($apertura->cerrada_en);
        $this->assertEqualsWithDelta(120, (float) $apertura->monto_sistema, 0.001);
        $this->assertEqualsWithDelta(115, (float) $apertura->monto_cierre, 0.001);

        // el turno cerrado aparece en el historial con su arqueo
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($pagina) => $pagina
            ->component('Caja/Index')
            ->has('historial.data', 1)
            ->where('historial.data.0.monto_inicial', fn ($v) => abs($v - 100) < 0.001)
            ->where('historial.data.0.ingresos', fn ($v) => abs($v - 50) < 0.001)
            ->where('historial.data.0.egresos', fn ($v) => abs($v - 30) < 0.001)
            ->where('historial.data.0.monto_sistema', fn ($v) => abs($v - 120) < 0.001)
            ->where('historial.data.0.diferencia', fn ($v) => abs($v - (-5)) < 0.001)
        );
    }

    public function test_cierra_sin_contar_con_lo_esperado_y_guarda_la_observacion(): void
    {
        // como en el sistema anterior: los campos vienen prellenados con lo esperado y cerrar sin tocar nada deja la caja cuadrada
        $apertura = $this->abrirCaja(100);
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'ingreso', 'concepto' => 'Sencillo', 'monto' => 20]);

        $respuesta = $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => null, 'observaciones' => '  Todo en orden  ']);
        $respuesta->assertSessionHas('success', fn ($m) => str_contains($m, 'cuadrada'));

        $apertura->refresh();
        $this->assertEqualsWithDelta(120, (float) $apertura->monto_sistema, 0.001);
        $this->assertEqualsWithDelta(120, (float) $apertura->monto_cierre, 0.001);
        $this->assertSame('Todo en orden', $apertura->observaciones);

        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($pagina) => $pagina
            ->where('historial.data.0.observaciones', 'Todo en orden')
            ->where('historial.data.0.diferencia', fn ($v) => abs($v) < 0.001)
        );
    }

    public function test_conteo_por_billetes_en_cero_cierra_con_cero_contado(): void
    {
        // el cajero abre el conteo por billetes y no escribe nada: contó 0, no "sin dato"
        $apertura = $this->abrirCaja(120);

        $respuesta = $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 0, 'conteo' => ['200' => 0]]);
        $respuesta->assertSessionHas('success', fn ($m) => str_contains($m, 'Faltan S/ 120.00'));

        $apertura->refresh();
        $this->assertEqualsWithDelta(0, (float) $apertura->monto_cierre, 0.001);
        $this->assertNull($apertura->conteo_efectivo);
    }

    public function test_cierra_con_saldo_negativo_y_lo_declarado_por_medio(): void
    {
        // la caja puede quedar en negativo (compra mayor a lo vendido): el contado prellenado es negativo y se acepta
        $apertura = $this->abrirCaja(0);
        $this->actingAs($this->admin)->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Compra de mercadería', 'monto' => 300]);
        MovimientoCaja::create(['empresa_id' => $this->empresa->id, 'apertura_id' => $apertura->id, 'usuario_id' => $this->admin->id, 'tipo' => 'ingreso', 'concepto' => 'Cobro por transferencia', 'monto' => 30, 'medio_pago_codigo' => 'transferencia']);

        // efectivo tal cual (−300) y transferencia declarada en 25 en vez de 30: falta 5 en ese medio
        $respuesta = $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => -300, 'declarados' => ['transferencia' => 25]]);
        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertSessionHas('success', fn ($m) => str_contains($m, 'Faltan S/ 5.00 en Transferencia bancaria'));

        $apertura->refresh();
        $this->assertEqualsWithDelta(-300, (float) $apertura->monto_sistema, 0.001);
        $this->assertEqualsWithDelta(-300, (float) $apertura->monto_cierre, 0.001);
        $this->assertEqualsWithDelta(-5, (float) $apertura->cierresMedios()->where('medio_pago_codigo', 'transferencia')->value('diferencia'), 0.001);
        $this->assertEqualsWithDelta(0, (float) $apertura->cierresMedios()->where('medio_pago_codigo', 'efectivo')->value('diferencia'), 0.001);
    }

    public function test_el_cajero_solo_ve_sus_propios_turnos_en_el_historial(): void
    {
        // turno cerrado del admin
        $apertura = $this->abrirCaja(100);
        $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => 100]);

        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        // el cajero no ve el turno del admin; el admin si lo ve
        $this->actingAs($cajero)->get('/caja')->assertInertia(fn ($pagina) => $pagina->has('historial.data', 0));
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($pagina) => $pagina->has('historial.data', 1));
    }

    public function test_no_permite_doble_apertura_del_mismo_usuario(): void
    {
        $this->abrirCaja(100);

        $respuesta = $this->actingAs($this->admin)
            ->post('/caja/abrir', ['caja_id' => $this->caja->id, 'monto_inicial' => 50]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(1, AperturaCaja::where('empresa_id', $this->empresa->id)->whereNull('cerrada_en')->count());
    }

    public function test_usuario_asignado_solo_ve_y_abre_cajas_de_sus_sucursales(): void
    {
        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);
        $cajaSecundaria = Caja::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $secundaria->id,
            'nombre' => 'Caja 2',
            'activo' => true,
        ]);

        // cajero asignado solo a la secundaria
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $cajero->update(['sucursal_id' => $secundaria->id]);
        $cajero->sucursales()->sync([$secundaria->id]);

        // solo ve la caja de su sucursal
        $this->actingAs($cajero)->get('/caja')->assertInertia(fn ($pagina) => $pagina
            ->has('cajas', 1)
            ->where('cajas.0.nombre', 'Caja 2')
        );

        // no puede abrir la caja de la sucursal principal
        $this->actingAs($cajero)
            ->post('/caja/abrir', ['caja_id' => $this->caja->id, 'monto_inicial' => 50])
            ->assertSessionHas('error');
        $this->assertSame(0, AperturaCaja::where('usuario_id', $cajero->id)->count());

        // si puede abrir la de su sucursal
        $this->actingAs($cajero)
            ->post('/caja/abrir', ['caja_id' => $cajaSecundaria->id, 'monto_inicial' => 50])
            ->assertSessionHas('success');

        // el admin (sin restriccion) ve las cajas de ambas sucursales
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($pagina) => $pagina->has('cajas', 2));
    }

    public function test_historial_suma_todos_los_turnos_del_rango_y_el_total_por_todos_los_medios(): void
    {
        // 12 turnos cerrados (más que una página de 10): la suma debe incluirlos todos, no solo los visibles
        for ($i = 1; $i <= 12; $i++) {
            $turno = $this->abrirCaja(100);
            MovimientoCaja::create(['empresa_id' => $this->empresa->id, 'apertura_id' => $turno->id, 'usuario_id' => $this->admin->id, 'tipo' => 'ingreso', 'concepto' => 'Venta suelta', 'monto' => 50, 'medio_pago_codigo' => 'efectivo']);
            MovimientoCaja::create(['empresa_id' => $this->empresa->id, 'apertura_id' => $turno->id, 'usuario_id' => $this->admin->id, 'tipo' => 'ingreso', 'concepto' => 'Cobro Yape', 'monto' => 20, 'medio_pago_codigo' => 'yape']);
            MovimientoCaja::create(['empresa_id' => $this->empresa->id, 'apertura_id' => $turno->id, 'usuario_id' => $this->admin->id, 'tipo' => 'egreso', 'concepto' => 'Pago', 'monto' => 10, 'medio_pago_codigo' => 'efectivo']);
            $this->actingAs($this->admin)->post('/caja/cerrar', ['monto_cierre' => null])->assertSessionHas('success');
            // cada turno en un día distinto, del 1 al 12 de octubre
            $turno->update(['abierta_en' => "2026-10-".str_pad((string) $i, 2, '0', STR_PAD_LEFT).' 08:00:00']);
        }

        // por turno: efectivo 100 + 50 - 10 = 140 esperado; total del turno = 140 + 20 (Yape) - 100 (inicial) = 60
        $this->actingAs($this->admin)->get('/caja')->assertInertia(fn ($p) => $p
            ->has('historial.data', 10)
            ->where('historial.data.0.total_turno', fn ($v) => abs($v - 60) < 0.001)
            ->where('suma.turnos', 12)
            ->where('suma.inicial', fn ($v) => abs($v - 1200) < 0.001)
            ->where('suma.ingresos', fn ($v) => abs($v - 600) < 0.001)
            ->where('suma.egresos', fn ($v) => abs($v - 120) < 0.001)
            ->where('suma.esperado', fn ($v) => abs($v - 1680) < 0.001)
            ->where('suma.diferencia', fn ($v) => abs($v) < 0.001)
            ->where('suma.total_turno', fn ($v) => abs($v - 720) < 0.001)
        );

        // filtrado del 1 al 5 de octubre: 5 turnos
        $this->actingAs($this->admin)->get('/caja?desde=2026-10-01&hasta=2026-10-05')->assertInertia(fn ($p) => $p
            ->has('historial.data', 5)
            ->where('suma.turnos', 5)
            ->where('suma.total_turno', fn ($v) => abs($v - 300) < 0.001)
            ->where('filtros.desde', '2026-10-01')
        );

        // un rango invertido se rechaza
        $this->actingAs($this->admin)->get('/caja?desde=2026-10-05&hasta=2026-10-01')->assertSessionHasErrors('hasta');
    }

    public function test_egreso_mayor_al_efectivo_disponible_se_registra_y_la_caja_queda_en_negativo(): void
    {
        // el dinero salió del negocio igual (pago del local, de un proveedor): se registra y el cierre muestra el negativo
        $apertura = $this->abrirCaja(100);

        $respuesta = $this->actingAs($this->admin)
            ->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Pago de local', 'monto' => 500]);

        $respuesta->assertSessionHas('success');
        $this->assertSame(1, $apertura->movimientos()->count());
        $this->assertEqualsWithDelta(-400.0, app(\App\Services\CajaService::class)->resumen($apertura)['esperado'], 0.001);
    }
}
