<?php

namespace Tests\Feature;

use App\Models\AperturaCaja;
use App\Models\Caja;
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

    public function test_egreso_mayor_al_efectivo_disponible_es_bloqueado(): void
    {
        $apertura = $this->abrirCaja(100);

        $respuesta = $this->actingAs($this->admin)
            ->post('/caja/movimientos', ['tipo' => 'egreso', 'concepto' => 'Retiro grande', 'monto' => 500]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, $apertura->movimientos()->count());
    }
}
