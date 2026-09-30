<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\Usuario;
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
            'max_sucursales' => 5, 'max_usuarios' => 15, 'max_comprobantes_mes' => null, 'activo' => true, 'publico' => true, 'orden' => 2,
        ])->assertSessionHas('success');
        $negocio->refresh();
        $this->assertSame(119.0, (float) $negocio->precio_mensual);
        $this->assertSame(5, (int) $negocio->max_sucursales);

        $prueba = Plan::where('codigo', 'prueba')->firstOrFail();
        $super->put("/admin/planes/{$prueba->id}", [
            'nombre' => 'Prueba gratuita', 'descripcion' => null, 'precio_mensual' => 0,
            'max_sucursales' => 2, 'max_usuarios' => 5, 'max_comprobantes_mes' => 300, 'activo' => false, 'publico' => true, 'orden' => 0,
        ])->assertSessionHas('success');
        $this->assertTrue($prueba->fresh()->activo);
    }

    public function test_el_superadmin_crea_planes_y_los_privados_no_salen_en_la_landing(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $super = $this->actingAs($this->admin->fresh());

        $plan = [
            'nombre' => 'Corporativo', 'descripcion' => 'Plan a medida', 'precio_mensual' => 450,
            'max_sucursales' => null, 'max_usuarios' => null, 'max_comprobantes_mes' => null,
            'activo' => true, 'publico' => false, 'orden' => 4,
        ];
        $super->post('/admin/planes', $plan)->assertSessionHas('success');

        $creado = Plan::where('nombre', 'Corporativo')->firstOrFail();
        $this->assertSame('corporativo', $creado->codigo);
        $this->assertFalse($creado->publico);
        $this->assertNull($creado->max_sucursales); // ilimitado
        $this->assertDatabaseHas('auditoria', ['accion' => 'plataforma.plan_creado', 'entidad_id' => $creado->id]);

        // nombre repetido
        $super->post('/admin/planes', $plan)->assertSessionHasErrors('nombre');

        // privado: no aparece en la landing ni en Suscripción, pero sí se puede asignar desde el panel
        $this->app['auth']->guard()->logout();
        $this->get('/')->assertInertia(fn ($p) => $p->has('planes', 3));
        // (el superadmin ya no entra a Suscripción: lo consulta un admin normal de la empresa)
        $this->actingAs($this->crearUsuario('admin', 'normal'.random_int(10000, 99999).'@test.local'))->get('/suscripcion')
            ->assertInertia(fn ($p) => $p->where('planes', fn ($planes) => collect($planes)->doesntContain('codigo', 'corporativo')));
        $this->actingAs($this->admin)->get("/admin/empresas/{$this->otra->id}")
            ->assertInertia(fn ($p) => $p->where('planes', fn ($planes) => collect($planes)->contains('codigo', 'corporativo')));

        // al hacerlo público sale en la landing
        $super->put("/admin/planes/{$creado->id}", ['publico' => true] + $plan)->assertSessionHas('success');
        $this->app['auth']->guard()->logout();
        $this->get('/')->assertInertia(fn ($p) => $p->has('planes', 4)->where('planes.3.codigo', 'corporativo'));
    }

    public function test_el_superadmin_solo_ve_la_plataforma(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $super = $this->actingAs($this->admin->fresh());

        $super->get('/admin/empresas')->assertOk();
        $super->get('/admin/cuenta')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Cuenta/Index'));

        // cualquier pantalla de empresa lo devuelve al panel
        foreach (['/dashboard', '/pos', '/compras', '/productos', '/usuarios', '/suscripcion'] as $ruta) {
            $super->get($ruta)->assertRedirect('/admin/empresas');
        }
        $super->getJson('/notificaciones')->assertForbidden();
        $super->post('/pos/ventas', [])->assertRedirect('/admin/empresas');
    }

    public function test_el_acceso_de_plataforma_se_mueve_a_otro_correo(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $emailNuevo = 'plataforma'.random_int(10000, 99999).'@inkanet.pro';

        $this->actingAs($this->admin->fresh())->post('/admin/cuenta/migrar', [
            'nombre_completo' => 'Admin inkaPos', 'email' => $emailNuevo,
            'password' => 'Plataforma-2026', 'password_confirmation' => 'Plataforma-2026',
        ])->assertRedirect('/login')->assertSessionHas('success');

        $nuevo = Usuario::where('email', $emailNuevo)->firstOrFail();
        $this->assertTrue($nuevo->es_superadmin);
        $this->assertFalse($this->admin->fresh()->es_superadmin);
        $this->assertDatabaseHas('auditoria', ['accion' => 'plataforma.superadmin_migrado', 'entidad_id' => $nuevo->id]);

        // la cuenta nueva solo ve la plataforma; la anterior vuelve a operar su empresa
        $this->actingAs($nuevo)->get('/admin/empresas')->assertOk();
        $this->actingAs($nuevo)->get('/pos')->assertRedirect('/admin/empresas');
        $this->actingAs($this->admin->fresh())->get('/dashboard')->assertOk();
        $this->actingAs($this->admin->fresh())->get('/admin/empresas')->assertForbidden();

        // la cuenta de plataforma no aparece ni cuenta como usuario de la empresa
        $this->actingAs($this->admin->fresh())->get('/usuarios')
            ->assertInertia(fn ($p) => $p->where('usuarios.data', fn ($lista) => collect($lista)->doesntContain('email', $emailNuevo)));
        $this->actingAs($this->admin->fresh())->put("/usuarios/{$nuevo->id}", [])->assertForbidden();
        $this->assertSame(1, Usuario::where('empresa_id', $this->empresa->id)->where('activo', true)->where('es_superadmin', false)->count());

        // el correo nuevo no puede repetirse
        $this->actingAs($nuevo)->post('/admin/cuenta/migrar', [
            'nombre_completo' => 'X', 'email' => $this->admin->email, 'password' => 'Plataforma-2026', 'password_confirmation' => 'Plataforma-2026',
        ])->assertSessionHasErrors('email');
    }

    public function test_el_comando_migra_el_superadmin(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $emailNuevo = 'cmd'.random_int(10000, 99999).'@inkanet.pro';

        $this->artisan('superadmin:migrar', ['email_actual' => $this->admin->email, 'email_nuevo' => $emailNuevo])
            ->expectsOutputToContain("Cuenta de plataforma creada: {$emailNuevo}")
            ->assertSuccessful();

        $this->assertTrue(Usuario::where('email', $emailNuevo)->value('es_superadmin'));
        $this->assertFalse($this->admin->fresh()->es_superadmin);

        $this->artisan('superadmin:migrar', ['email_actual' => $this->admin->email, 'email_nuevo' => 'otro@inkanet.pro'])->assertFailed();
    }

    private function duenoDeOtra(string $rol = 'admin', bool $activo = true): Usuario
    {
        return Usuario::create([
            'empresa_id' => $this->otra->id,
            'rol_id' => Rol::where('codigo', $rol)->value('id'),
            'email' => 'dueno'.random_int(10000, 99999).'@otra.local',
            'password_hash' => 'clave12345',
            'nombre_completo' => 'Dueño Otra',
            'activo' => $activo,
            'email_verificado_en' => now(),
        ]);
    }

    public function test_el_superadmin_entra_como_dueno_y_vuelve_a_la_plataforma(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $dueno = $this->duenoDeOtra();

        $this->actingAs($this->admin->fresh())->post("/admin/empresas/{$this->otra->id}/entrar")
            ->assertRedirect('/dashboard')->assertSessionHas('success');
        $this->assertAuthenticatedAs($dueno);

        // ve la empresa como el dueño, con la franja de soporte
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->where('auth.user.id', $dueno->id)
            ->where('impersonacion.usuario', 'Dueño Otra')
            ->where('impersonacion.superadmin', $this->admin->nombre_completo));
        $this->get('/pos')->assertOk();

        // queda registrado en la auditoría de la plataforma y de la empresa
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->empresa->id, 'accion' => 'plataforma.entro_como', 'entidad_id' => $dueno->id]);
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->otra->id, 'accion' => 'soporte.ingreso', 'usuario_id' => $this->admin->id]);

        // en modo soporte no se cambian contraseñas ni se eliminan usuarios
        $cajero = $this->duenoDeOtra('cajero');
        $this->put("/usuarios/{$cajero->id}", [
            'nombre_completo' => $cajero->nombre_completo, 'email' => $cajero->email, 'rol_id' => $cajero->rol_id,
            'sucursal_ids' => [], 'password' => 'Nueva-12345', 'password_confirmation' => 'Nueva-12345', 'activo' => true,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'soporte'));
        $this->delete("/usuarios/{$cajero->id}")->assertSessionHas('error', fn ($m) => str_contains($m, 'soporte'));
        $this->assertDatabaseHas('usuarios', ['id' => $cajero->id]);

        // vuelve a la plataforma
        $this->post('/volver-plataforma')->assertRedirect('/admin/empresas');
        $this->assertAuthenticatedAs($this->admin);
        $this->get('/admin/empresas')->assertOk()->assertInertia(fn ($p) => $p->where('impersonacion', null));
        $this->assertDatabaseHas('auditoria', ['empresa_id' => $this->otra->id, 'accion' => 'soporte.salida']);
    }

    public function test_entrar_como_valida_usuario_y_permisos(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $super = $this->actingAs($this->admin->fresh());

        // sin administrador activo no hay con quién entrar
        $super->post("/admin/empresas/{$this->otra->id}/entrar")->assertSessionHas('error', fn ($m) => str_contains($m, 'administrador activo'));
        $this->assertAuthenticatedAs($this->admin);

        // un usuario de otra empresa o inactivo no vale
        $inactivo = $this->duenoDeOtra('admin', activo: false);
        $super->post("/admin/empresas/{$this->otra->id}/entrar", ['usuario_id' => $inactivo->id])->assertSessionHas('error');
        $super->post("/admin/empresas/{$this->otra->id}/entrar", ['usuario_id' => $this->admin->id])->assertSessionHas('error');

        // solo el superadmin puede entrar como otro
        $cajero = $this->crearUsuario('cajero', 'caj'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->post("/admin/empresas/{$this->otra->id}/entrar")->assertForbidden();
    }

    public function test_la_sesion_como_dueno_expira_a_las_dos_horas(): void
    {
        $this->admin->forceFill(['es_superadmin' => true])->save();
        $dueno = $this->duenoDeOtra();

        $this->actingAs($this->admin->fresh())->post("/admin/empresas/{$this->otra->id}/entrar")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($dueno);

        $this->travel(3)->hours();
        $this->get('/pos')->assertRedirect('/admin/empresas')->assertSessionHas('error', fn ($m) => str_contains($m, 'expiró'));
        $this->assertAuthenticatedAs($this->admin);
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
