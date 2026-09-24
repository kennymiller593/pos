<?php

namespace Tests\Feature;

use App\Mail\VerificarCorreo;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistroVerificacionTest extends TestCase
{
    use DatabaseTransactions;

    private const RUC_VALIDO = '20123456786';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    private function datos(array $extra = []): array
    {
        $sufijo = random_int(10000, 99999);

        return [
            'ruc' => self::RUC_VALIDO,
            'razon_social' => 'Nueva SAC',
            'nombre_comercial' => null,
            'rubro_codigo' => Rubro::query()->value('codigo'),
            'regimen_tributario' => 'RUS',
            'direccion' => null,
            'ubigeo' => null,
            'nombre_completo' => 'Dueño',
            'email' => "dueno{$sufijo}@test.local",
            'password' => 'clave2026',
            'password_confirmation' => 'clave2026',
            ...$extra,
        ];
    }

    public function test_rechaza_ruc_con_digito_verificador_incorrecto(): void
    {
        $this->post('/registro', $this->datos(['ruc' => '20123456789']))->assertSessionHasErrors('ruc');
    }

    private function conDecolecta(): void
    {
        Config::set('services.decolecta.token', 'token-de-prueba');
        Config::set('services.decolecta.url', 'https://api.decolecta.test/v1');
    }

    public function test_con_decolecta_un_ruc_no_activo_se_rechaza(): void
    {
        $this->conDecolecta();
        Http::fake(['api.decolecta.test/*' => Http::response(['numero_documento' => self::RUC_VALIDO, 'razon_social' => 'X', 'estado' => 'BAJA DE OFICIO'], 200)]);

        $this->post('/registro', $this->datos())->assertSessionHasErrors('ruc');
        $this->assertStringContainsString('BAJA DE OFICIO', session('errors')->first('ruc'));
    }

    public function test_con_decolecta_un_ruc_inexistente_se_rechaza(): void
    {
        $this->conDecolecta();
        Http::fake(['api.decolecta.test/*' => Http::response(['message' => 'no existe'], 422)]);

        $this->post('/registro', $this->datos())->assertSessionHasErrors('ruc');
        $this->assertStringContainsString('no figura', session('errors')->first('ruc'));
    }

    public function test_si_decolecta_no_responde_el_registro_no_se_bloquea(): void
    {
        $this->conDecolecta();
        Http::fake(['api.decolecta.test/*' => fn () => throw new ConnectionException('timeout')]);

        $this->post('/registro', $this->datos())->assertRedirect('/dashboard');
    }

    public function test_al_registrarse_se_envia_el_enlace_y_confirmarlo_marca_el_correo(): void
    {
        $datos = $this->datos();
        $this->post('/registro', $datos)->assertRedirect('/dashboard');

        $usuario = Usuario::where('email', $datos['email'])->firstOrFail();
        $this->assertNull($usuario->email_verificado_en);

        Mail::assertSent(VerificarCorreo::class, fn (VerificarCorreo $m) => $m->hasTo($datos['email']));

        // aun en gracia: se puede usar el sistema y el layout lo avisa
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->where('auth.user.correo_verificado', false)
            ->where('auth.user.dias_para_verificar', 3));

        // un enlace manipulado no confirma nada
        $this->get(URL::temporarySignedRoute('verificacion.verificar', now()->addDay(), ['usuario' => $usuario->id, 'hash' => sha1('otro@correo')]))
            ->assertRedirect('/login');
        $this->assertNull($usuario->fresh()->email_verificado_en);

        $this->get(URL::temporarySignedRoute('verificacion.verificar', now()->addDay(), ['usuario' => $usuario->id, 'hash' => sha1($usuario->email)]))
            ->assertRedirect('/dashboard');
        $this->assertNotNull($usuario->fresh()->email_verificado_en);
    }

    public function test_pasada_la_gracia_solo_queda_confirmar_o_reenviar(): void
    {
        $datos = $this->datos();
        $this->post('/registro', $datos)->assertRedirect('/dashboard');
        $usuario = Usuario::where('email', $datos['email'])->firstOrFail();
        $usuario->forceFill(['creado_en' => now()->subDays(4)])->save();

        $this->actingAs($usuario->fresh())->get('/dashboard')->assertRedirect('/verificar-correo');
        $this->actingAs($usuario->fresh())->get('/pos')->assertRedirect('/verificar-correo');
        $this->actingAs($usuario->fresh())->get('/verificar-correo')->assertOk();

        $this->actingAs($usuario->fresh())->post('/verificar-correo/reenviar')->assertSessionHas('success');
        Mail::assertSent(VerificarCorreo::class, 2);

        $usuario->forceFill(['email_verificado_en' => now()])->save();
        $this->actingAs($usuario->fresh())->get('/dashboard')->assertOk();
    }

    public function test_los_usuarios_creados_por_un_admin_no_necesitan_confirmar(): void
    {
        $datos = $this->datos();
        $this->post('/registro', $datos)->assertRedirect('/dashboard');
        $admin = Usuario::where('email', $datos['email'])->firstOrFail();
        $admin->forceFill(['email_verificado_en' => now()])->save();

        $email = 'cajero'.random_int(10000, 99999).'@test.local';
        $this->actingAs($admin->fresh())->post('/usuarios', [
            'nombre_completo' => 'Cajero', 'email' => $email, 'password' => 'clave2026', 'password_confirmation' => 'clave2026',
            'rol_id' => Rol::where('codigo', 'cajero')->value('id'), 'activo' => true,
        ])->assertSessionHas('success');

        $this->assertNotNull(Usuario::where('email', $email)->value('email_verificado_en'));
    }
}
