<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\Usuario;
use App\Services\SuscripcionService;
use App\Support\DocumentoIdentidad;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class SuscripcionTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    /** Mueve la prueba al pasado (la BD exige fecha_fin > fecha_inicio). */
    private function vencerSuscripcion(int $diasAtras = 10): void
    {
        Suscripcion::where('empresa_id', $this->empresa->id)->update([
            'fecha_inicio' => now()->subDays($diasAtras + 14)->toDateString(),
            'fecha_fin' => now()->subDays($diasAtras)->toDateString(),
        ]);
    }

    public function test_el_registro_crea_una_prueba_gratuita_de_14_dias(): void
    {
        $ruc = DocumentoIdentidad::completarRuc('20'.random_int(10000000, 99999999));

        $this->post('/registro', [
            'ruc' => $ruc,
            'razon_social' => 'Nueva Empresa SAC',
            'nombre_comercial' => null,
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'regimen_tributario' => 'RUS',
            'direccion' => null,
            'ubigeo' => null,
            'nombre_completo' => 'Dueño',
            'email' => "dueno{$ruc}@test.local",
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
        ])->assertRedirect('/dashboard');

        $empresa = Empresa::where('ruc', $ruc)->firstOrFail();
        $suscripcion = Suscripcion::where('empresa_id', $empresa->id)->firstOrFail();

        $this->assertTrue($suscripcion->es_prueba);
        $this->assertSame('prueba', $suscripcion->plan->codigo);
        $this->assertSame(now()->addDays(SuscripcionService::DIAS_PRUEBA)->toDateString(), $suscripcion->fecha_fin->toDateString());

        $this->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('auth.user.suscripcion.es_prueba', true)
            ->where('auth.user.suscripcion.vigente', true)
            ->where('auth.user.suscripcion.dias_restantes', SuscripcionService::DIAS_PRUEBA));
    }

    public function test_con_la_suscripcion_vencida_solo_se_puede_ver_la_pantalla_de_suscripcion(): void
    {
        $this->vencerSuscripcion();

        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/suscripcion');
        $this->actingAs($this->admin)->get('/pos')->assertRedirect('/suscripcion');
        $this->actingAs($this->admin)->get('/suscripcion')->assertOk()
            ->assertInertia(fn ($p) => $p->where('suscripcion.estado', 'vencida')->where('suscripcion.vigente', false));

        $this->actingAs($this->admin)->post('/logout')->assertRedirect('/login');
    }

    public function test_en_los_dias_de_gracia_se_sigue_operando(): void
    {
        $this->vencerSuscripcion(diasAtras: 2);

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($p) => $p->where('auth.user.suscripcion.estado', 'en_gracia'));
    }

    public function test_una_empresa_desactivada_no_entra(): void
    {
        $this->empresa->update(['activo' => false]);

        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_el_plan_limita_usuarios_sucursales_y_comprobantes(): void
    {
        $plan = Plan::where('codigo', 'prueba')->firstOrFail();
        $plan->update(['max_usuarios' => 1, 'max_sucursales' => 1, 'max_comprobantes_mes' => 1]);

        $this->actingAs($this->admin)->post('/usuarios', [
            'nombre_completo' => 'Otro', 'email' => 'otro'.random_int(1000, 99999).'@test.local',
            'password' => 'secreto123', 'password_confirmation' => 'secreto123',
            'rol_id' => $this->admin->rol_id, 'activo' => true, 'sucursal_ids' => [$this->sucursal->id],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'límite'));
        $this->assertSame(1, Usuario::where('empresa_id', $this->empresa->id)->count());

        $this->actingAs($this->admin)->post('/sucursales', [
            'codigo_sunat' => '0001', 'nombre' => 'Sede 2', 'direccion' => null, 'ubigeo' => null, 'activo' => true,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'límite'));

        $producto = $this->crearProducto(precio: 5.00);
        $this->darStock($producto, 10, 1.00);
        $this->abrirCaja();
        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('success');
        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('error', fn ($m) => str_contains($m, 'límite'));

        // al ampliar el plan, vuelve a vender
        $plan->update(['max_comprobantes_mes' => null]);
        $this->venderContado($producto->presentaciones->first(), 1)->assertSessionHas('success');
    }

    public function test_activar_un_plan_reemplaza_la_prueba_y_renovar_extiende_desde_el_fin(): void
    {
        $servicio = app(SuscripcionService::class);
        $negocio = Plan::where('codigo', 'negocio')->firstOrFail();

        $primera = $servicio->activar($this->empresa, $negocio, meses: 1, nota: 'pago 1');
        $this->assertSame(now()->toDateString(), $primera->fecha_inicio->toDateString());
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $primera->fecha_fin->toDateString());
        $this->assertSame('vencida', Suscripcion::where('empresa_id', $this->empresa->id)->where('es_prueba', true)->value('estado'));

        // renovar el mismo plan extiende la misma suscripcion (solo puede haber una activa)
        $renovada = $servicio->activar($this->empresa, $negocio, meses: 2, nota: 'pago 2');
        $this->assertSame($primera->id, $renovada->id);
        $this->assertSame($primera->fecha_inicio->toDateString(), $renovada->fecha_inicio->toDateString());
        $this->assertSame($primera->fecha_fin->copy()->addMonthsNoOverflow(2)->toDateString(), $renovada->fecha_fin->toDateString());
        $this->assertSame('pago 1 | pago 2', $renovada->nota);
        $this->assertSame(1, Suscripcion::where('empresa_id', $this->empresa->id)->where('estado', 'activa')->count());

        // cambiar de plan cierra la anterior y abre otra desde hoy
        $empresaPlan = Plan::where('codigo', 'empresa')->firstOrFail();
        $cambiada = $servicio->activar($this->empresa, $empresaPlan, meses: 1);
        $this->assertNotSame($primera->id, $cambiada->id);
        $this->assertSame(now()->toDateString(), $cambiada->fecha_inicio->toDateString());
        $this->assertSame('vencida', $primera->fresh()->estado);

        $resumen = $servicio->resumen($this->empresa);
        $this->assertSame('Empresa', $resumen['plan']);
        $this->assertFalse($resumen['es_prueba']);
        $this->assertSame($cambiada->fecha_fin->toDateString(), $resumen['fecha_fin']);
    }

    public function test_resumen_con_plan_negocio(): void
    {
        $servicio = app(SuscripcionService::class);
        $negocio = Plan::where('codigo', 'negocio')->firstOrFail();
        $renovada = $servicio->activar($this->empresa, $negocio, meses: 1);

        $resumen = $servicio->resumen($this->empresa);
        $this->assertSame('Negocio', $resumen['plan']);
        $this->assertFalse($resumen['es_prueba']);
        $this->assertSame($renovada->fecha_fin->toDateString(), $resumen['fecha_fin']);
    }

    public function test_el_comando_activa_planes_por_ruc(): void
    {
        $this->artisan('suscripcion:activar', ['ruc' => $this->empresa->ruc, 'plan' => 'emprendedor', '--meses' => 3])
            ->assertSuccessful();

        $vigente = app(SuscripcionService::class)->vigente($this->empresa);
        $this->assertSame('emprendedor', $vigente->plan->codigo);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $vigente->fecha_fin->toDateString());

        $this->artisan('suscripcion:activar', ['ruc' => '00000000000', 'plan' => 'negocio'])->assertFailed();
    }

    public function test_el_comando_de_vencimiento_marca_las_pasadas(): void
    {
        $this->vencerSuscripcion(diasAtras: 10);

        $this->artisan('suscripciones:vencer')->assertSuccessful();

        $this->assertSame('vencida', Suscripcion::where('empresa_id', $this->empresa->id)->value('estado'));
    }
}
