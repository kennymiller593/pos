<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
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
}
