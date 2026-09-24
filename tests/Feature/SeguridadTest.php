<?php

namespace Tests\Feature;

use App\Models\Rubro;
use App\Models\Usuario;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class SeguridadTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_las_respuestas_llevan_cabeceras_defensivas(): void
    {
        $respuesta = $this->get('/login');

        $respuesta->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_el_login_se_limita_por_correo_e_ip(): void
    {
        RateLimiter::clear('login');
        $email = $this->admin->email;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $email, 'password' => 'incorrecta'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $email, 'password' => 'incorrecta'])->assertStatus(429);

        // otro correo desde la misma IP sigue pudiendo entrar
        $otro = $this->crearUsuario('cajero', 'otro'.random_int(10000, 99999).'@test.local');
        $this->post('/login', ['email' => $otro->email, 'password' => 'secreto123'])->assertRedirect('/');
    }

    public function test_la_contrasena_exige_letras_y_numeros_y_el_correo_se_guarda_en_minusculas(): void
    {
        $ruc = '20'.random_int(100000000, 999999999);
        $base = [
            'ruc' => $ruc, 'razon_social' => 'Nueva SAC', 'nombre_comercial' => null,
            'rubro_codigo' => Rubro::query()->value('codigo'), 'regimen_tributario' => 'RUS',
            'direccion' => null, 'ubigeo' => null, 'nombre_completo' => 'Dueño',
            'email' => "Dueno{$ruc}@Test.Local",
        ];

        $this->post('/registro', [...$base, 'password' => '12345678', 'password_confirmation' => '12345678'])
            ->assertSessionHasErrors('password');
        $this->post('/registro', [...$base, 'password' => 'soloLetras', 'password_confirmation' => 'soloLetras'])
            ->assertSessionHasErrors('password');

        $this->post('/registro', [...$base, 'password' => 'clave2026', 'password_confirmation' => 'clave2026'])
            ->assertRedirect('/');

        $this->assertSame(mb_strtolower("Dueno{$ruc}@Test.Local"), Usuario::where('email', mb_strtolower("Dueno{$ruc}@Test.Local"))->value('email'));

        // y al entrar da igual como se escriba
        $this->post('/logout');
        $this->post('/login', ['email' => "DUENO{$ruc}@TEST.LOCAL", 'password' => 'clave2026'])->assertRedirect('/');
    }

    public function test_los_mensajes_de_validacion_estan_en_espanol(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', [
            'nombre_completo' => 'X', 'email' => 'x'.random_int(1000, 9999).'@test.local',
            'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh',
            'rol_id' => $this->admin->rol_id, 'activo' => true,
        ])->assertSessionHasErrors(['password' => 'La contraseña debe incluir al menos un número.']);
    }
}
