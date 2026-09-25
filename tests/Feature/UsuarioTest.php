<?php

namespace Tests\Feature;

use App\Mail\BienvenidaUsuario;
use App\Models\AperturaCaja;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class UsuarioTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_el_admin_crea_un_cajero_y_este_puede_loguearse(): void
    {
        $email = 'cajero'.random_int(10000, 99999).'@test.local';

        $respuesta = $this->actingAs($this->admin)->post('/usuarios', [
            'nombre_completo' => 'Cajero Nuevo',
            'email' => $email,
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'),
            'sucursal_ids' => [$this->sucursal->id],
            'password' => 'clave12345',
            'password_confirmation' => 'clave12345',
            'activo' => true,
        ]);

        $respuesta->assertSessionHas('success');

        $cajero = Usuario::where('email', $email)->firstOrFail();
        $this->assertSame('cajero', $cajero->rol->codigo);
        $this->assertTrue(Hash::check('clave12345', $cajero->password_hash));
        $this->assertSame($this->sucursal->id, $cajero->sucursal_id);
        $this->assertSame([$this->sucursal->id], $cajero->sucursales()->pluck('sucursales.id')->all());
    }

    private function datosNuevoUsuario(array $extra = []): array
    {
        return $extra + [
            'nombre_completo' => 'Rosa Cajera',
            'email' => 'rosa'.random_int(10000, 99999).'@test.local',
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'),
            'sucursal_ids' => [$this->sucursal->id],
            'password' => 'Clave-Rosa-2026',
            'password_confirmation' => 'Clave-Rosa-2026',
            'activo' => true,
        ];
    }

    public function test_al_crear_un_usuario_se_le_envian_sus_datos_de_acceso(): void
    {
        Mail::fake();
        $datos = $this->datosNuevoUsuario(['enviar_correo' => true]);

        $this->actingAs($this->admin)->post('/usuarios', $datos)
            ->assertSessionHas('success', fn ($m) => str_contains($m, $datos['email']));

        Mail::assertSent(BienvenidaUsuario::class, function (BienvenidaUsuario $correo) use ($datos) {
            $html = $correo->render();

            return $correo->hasTo($datos['email'])
                && str_contains($html, 'Clave-Rosa-2026')
                && str_contains($html, route('login'))
                && str_contains($html, 'Rosa Cajera');
        });
    }

    public function test_sin_el_toggle_no_se_envia_correo(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post('/usuarios', $this->datosNuevoUsuario(['enviar_correo' => false]))
            ->assertSessionHas('success', 'Usuario creado.');

        Mail::assertNothingSent();
    }

    public function test_si_el_correo_falla_el_usuario_igual_queda_creado(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));
        $datos = $this->datosNuevoUsuario(['enviar_correo' => true]);

        $this->actingAs($this->admin)->post('/usuarios', $datos)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no se pudo enviar el correo'));

        $this->assertDatabaseHas('usuarios', ['email' => $datos['email']]);
    }

    public function test_asignar_varias_sucursales_a_un_usuario(): void
    {
        $secundaria = Sucursal::create([
            'empresa_id' => $this->empresa->id,
            'codigo_sunat' => '0001',
            'nombre' => 'Secundaria',
            'activo' => true,
        ]);
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($this->admin)->put("/usuarios/{$cajero->id}", [
            'nombre_completo' => $cajero->nombre_completo,
            'email' => $cajero->email,
            'rol_id' => $cajero->rol_id,
            'sucursal_ids' => [$this->sucursal->id, $secundaria->id],
            'password' => null,
            'password_confirmation' => null,
            'activo' => true,
        ])->assertSessionHas('success');

        $cajero->refresh();
        $this->assertSame($this->sucursal->id, $cajero->sucursal_id);
        $this->assertEqualsCanonicalizing(
            [$this->sucursal->id, $secundaria->id],
            $cajero->sucursales()->pluck('sucursales.id')->all()
        );
    }

    public function test_no_acepta_sucursales_de_otra_empresa(): void
    {
        $adminOriginal = $this->admin;
        $sucursalOriginal = $this->sucursal;

        // segunda empresa con su propia sucursal
        $this->crearEscenarioBase();
        $sucursalAjena = $this->sucursal;

        $this->actingAs($adminOriginal)->post('/usuarios', [
            'nombre_completo' => 'Cajero Intruso',
            'email' => 'intruso'.random_int(10000, 99999).'@test.local',
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'),
            'sucursal_ids' => [$sucursalOriginal->id, $sucursalAjena->id],
            'password' => 'clave12345',
            'password_confirmation' => 'clave12345',
            'activo' => true,
        ])->assertSessionHasErrors('sucursal_ids.1');
    }

    public function test_un_cajero_no_puede_entrar_a_usuarios(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        $this->actingAs($cajero)->get('/usuarios')->assertForbidden();
        $this->actingAs($cajero)->post('/usuarios', [])->assertForbidden();
    }

    public function test_no_puede_desactivarse_ni_quitarse_admin_a_si_mismo(): void
    {
        $payloadBase = [
            'nombre_completo' => $this->admin->nombre_completo,
            'email' => $this->admin->email,
            'sucursal_ids' => [],
            'password' => null,
            'password_confirmation' => null,
        ];

        // desactivarse
        $this->actingAs($this->admin)->put("/usuarios/{$this->admin->id}", [
            ...$payloadBase,
            'rol_id' => Rol::where('codigo', 'admin')->value('id'),
            'activo' => false,
        ])->assertSessionHas('error');

        // quitarse el rol admin
        $this->actingAs($this->admin)->put("/usuarios/{$this->admin->id}", [
            ...$payloadBase,
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'),
            'activo' => true,
        ])->assertSessionHas('error');

        $this->admin->refresh();
        $this->assertTrue($this->admin->activo);
        $this->assertSame('admin', $this->admin->rol->codigo);
    }

    public function test_editar_sin_password_conserva_la_actual(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');
        $hashOriginal = $cajero->password_hash;

        $this->actingAs($this->admin)->put("/usuarios/{$cajero->id}", [
            'nombre_completo' => 'Cajero Renombrado',
            'email' => $cajero->email,
            'rol_id' => Rol::where('codigo', 'vendedor')->value('id'),
            'sucursal_ids' => [],
            'password' => null,
            'password_confirmation' => null,
            'activo' => true,
        ])->assertSessionHas('success');

        $cajero->refresh();
        $this->assertSame('Cajero Renombrado', $cajero->nombre_completo);
        $this->assertSame('vendedor', $cajero->rol->codigo);
        $this->assertSame($hashOriginal, $cajero->password_hash);
    }

    public function test_un_usuario_desactivado_es_deslogueado_en_su_siguiente_peticion(): void
    {
        $cajero = $this->crearUsuario('cajero', 'cajero'.random_int(10000, 99999).'@test.local');

        // sesion activa funciona
        $this->actingAs($cajero)->get('/dashboard')->assertOk();

        // lo desactivan
        $cajero->update(['activo' => false]);

        // su siguiente peticion lo saca del sistema
        $this->actingAs($cajero)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_no_puede_editar_usuarios_de_otra_empresa(): void
    {
        $otroAdmin = $this->admin;

        // segunda empresa con su propio usuario
        $this->crearEscenarioBase();
        $usuarioAjeno = $this->admin;

        $this->actingAs($otroAdmin)->put("/usuarios/{$usuarioAjeno->id}", [
            'nombre_completo' => 'Hackeado',
            'email' => $usuarioAjeno->email,
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'),
            'sucursal_ids' => [],
            'password' => null,
            'password_confirmation' => null,
            'activo' => true,
        ])->assertForbidden();
    }

    public function test_se_elimina_un_usuario_sin_historial(): void
    {
        $cajero = $this->crearUsuario('cajero', 'nuevo'.random_int(10000, 99999).'@test.local');
        $cajero->sucursales()->sync([$this->sucursal->id]);

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertInertia(fn ($p) => $p->where('usuarios.data', fn ($lista) => collect($lista)->firstWhere('id', $cajero->id)['con_historial'] === false));

        $this->actingAs($this->admin)->delete("/usuarios/{$cajero->id}")->assertSessionHas('success');

        $this->assertDatabaseMissing('usuarios', ['id' => $cajero->id]);
        $this->assertDatabaseMissing('usuario_sucursales', ['usuario_id' => $cajero->id]);
        $this->assertDatabaseHas('auditoria', ['accion' => 'usuario.eliminado', 'entidad_id' => $cajero->id]);
    }

    public function test_un_usuario_con_historial_no_se_elimina(): void
    {
        $cajero = $this->crearUsuario('cajero', 'conhist'.random_int(10000, 99999).'@test.local');
        AperturaCaja::create([
            'empresa_id' => $this->empresa->id, 'caja_id' => $this->caja->id,
            'usuario_id' => $cajero->id, 'monto_inicial' => 50,
        ]);

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertInertia(fn ($p) => $p->where('usuarios.data', fn ($lista) => collect($lista)->firstWhere('id', $cajero->id)['con_historial'] === true));

        $this->actingAs($this->admin)->delete("/usuarios/{$cajero->id}")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Desactívalo'));

        $this->assertDatabaseHas('usuarios', ['id' => $cajero->id]);
    }

    public function test_no_se_puede_eliminar_a_si_mismo_ni_a_usuarios_de_otra_empresa(): void
    {
        $this->actingAs($this->admin)->delete("/usuarios/{$this->admin->id}")
            ->assertSessionHas('error', 'No puedes eliminarte a ti mismo.');
        $this->assertDatabaseHas('usuarios', ['id' => $this->admin->id]);

        $cajero = $this->crearUsuario('cajero', 'otro'.random_int(10000, 99999).'@test.local');
        $this->actingAs($cajero)->delete("/usuarios/{$this->admin->id}")->assertForbidden(); // sin permiso

        $ajeno = Usuario::where('empresa_id', '!=', $this->empresa->id)->first();
        if ($ajeno) {
            $this->actingAs($this->admin)->delete("/usuarios/{$ajeno->id}")->assertForbidden();
        }
    }
}
