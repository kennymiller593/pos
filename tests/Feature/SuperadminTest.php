<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Services\SuscripcionService;
use App\Support\DocumentoIdentidad;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class SuperadminTest extends TestCase
{
    use CreaEscenarioPos;

    private Empresa $otra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();

        $this->otra = Empresa::create([
            'ruc' => DocumentoIdentidad::completarRuc('20'.random_int(10000000, 99999999)),
            'razon_social' => 'Cliente Prueba SAC',
            'regimen_tributario' => 'RUS',
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'activo' => true,
        ]);
        app(SuscripcionService::class)->iniciarPrueba($this->otra);
    }

    public function test_solo_un_superadmin_entra_al_panel(): void
    {
        $this->actingAs($this->admin)->get('/admin/empresas')->assertForbidden();

        $this->artisan('superadmin:asignar', ['email' => $this->admin->email])->assertSuccessful();

        $this->actingAs($this->admin->fresh())->get('/admin/empresas')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Empresas/Index')
                ->where('auth.user.es_superadmin', true)
                ->has('empresas.data'));

        $this->artisan('superadmin:asignar', ['email' => $this->admin->email, '--quitar' => true])->assertSuccessful();
        $this->actingAs($this->admin->fresh())->get('/admin/empresas')->assertForbidden();
    }

    public function test_el_superadmin_activa_planes_extiende_y_suspende_empresas(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $super = $this->actingAs($this->admin->fresh());

        $super->get("/admin/empresas/{$this->otra->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Empresas/Show')
                ->where('empresa.razon_social', 'Cliente Prueba SAC')
                ->where('suscripcion.es_prueba', true));

        // activar plan tras el pago
        $super->post("/admin/empresas/{$this->otra->id}/plan", ['plan' => 'negocio', 'meses' => 3, 'nota' => 'Yape 24/09'])
            ->assertSessionHas('success');
        $vigente = app(SuscripcionService::class)->vigente($this->otra);
        $this->assertSame('negocio', $vigente->plan->codigo);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $vigente->fecha_fin->toDateString());
        $this->assertDatabaseHas('auditoria', ['entidad_id' => $this->otra->id, 'accion' => 'plataforma.plan_activado']);

        // extender 15 dias desde el fin actual
        $super->post("/admin/empresas/{$this->otra->id}/extender", ['dias' => 15])->assertSessionHas('success');
        $this->assertSame(now()->addMonthsNoOverflow(3)->addDays(15)->toDateString(), $vigente->fresh()->fecha_fin->toDateString());

        // suspender: sus usuarios ya no entran
        $super->post("/admin/empresas/{$this->otra->id}/activo")->assertSessionHas('success');
        $this->assertFalse($this->otra->fresh()->activo);
        $super->post("/admin/empresas/{$this->otra->id}/activo")->assertSessionHas('success');
        $this->assertTrue($this->otra->fresh()->activo);

        // no puede apagar su propia empresa
        $super->post("/admin/empresas/{$this->empresa->id}/activo")->assertSessionHas('error');
        $this->assertTrue($this->empresa->fresh()->activo);
    }

    public function test_el_superadmin_edita_planes_y_la_prueba_no_se_desactiva(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $super = $this->actingAs($this->admin->fresh());

        $super->get('/admin/planes')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Planes/Index')->has('planes', 4));

        $negocio = Plan::where('codigo', 'negocio')->firstOrFail();
        $super->put("/admin/planes/{$negocio->id}", [
            'nombre' => 'Negocio', 'descripcion' => 'Hasta 5 sucursales', 'precio_mensual' => 119,
            'max_sucursales' => 5, 'max_usuarios' => 15, 'max_comprobantes_mes' => null, 'activo' => true, 'orden' => 2,
        ])->assertSessionHas('success');
        $negocio->refresh();
        $this->assertSame(119.0, (float) $negocio->precio_mensual);
        $this->assertSame(5, (int) $negocio->max_sucursales);

        $prueba = Plan::where('codigo', 'prueba')->firstOrFail();
        $super->put("/admin/planes/{$prueba->id}", [
            'nombre' => 'Prueba gratuita', 'descripcion' => null, 'precio_mensual' => 0,
            'max_sucursales' => 2, 'max_usuarios' => 5, 'max_comprobantes_mes' => 300, 'activo' => false, 'orden' => 0,
        ])->assertSessionHas('success');
        $this->assertTrue($prueba->fresh()->activo);
    }

    public function test_el_superadmin_no_queda_bloqueado_por_su_propia_suscripcion(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        Suscripcion::where('empresa_id', $this->empresa->id)->update([
            'fecha_inicio' => now()->subDays(40)->toDateString(), 'fecha_fin' => now()->subDays(20)->toDateString(),
        ]);

        $this->actingAs($this->admin->fresh())->get('/admin/empresas')->assertOk();
    }
}
