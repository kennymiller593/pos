<?php

namespace Tests\Feature;

use App\Mail\RecuperarPassword;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\CreaEscenarioPos;
use Tests\TestCase;

class RecuperacionPasswordTest extends TestCase
{
    use CreaEscenarioPos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEscenarioBase();
    }

    public function test_solicitar_el_enlace_genera_token_y_envia_correo(): void
    {
        Mail::fake();

        $this->post('/olvide-password', ['email' => $this->admin->email])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(RecuperarPassword::class, fn ($mail) => $mail->hasTo($this->admin->email));
        $this->assertNotNull(DB::table('recuperaciones_password')->where('email', $this->admin->email)->first());
    }

    public function test_correo_no_registrado_responde_igual_pero_no_envia_nada(): void
    {
        Mail::fake();

        $this->post('/olvide-password', ['email' => 'nadie@test.local'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertNull(DB::table('recuperaciones_password')->where('email', 'nadie@test.local')->first());
    }

    public function test_restablece_la_password_de_todas_las_cuentas_con_ese_correo(): void
    {
        $email = $this->admin->email;

        // el mismo correo tambien tiene cuenta en una segunda empresa
        $primeraEmpresa = $this->empresa;
        $this->crearEscenarioBase();
        $cuentaGemela = Usuario::create([
            'empresa_id' => $this->empresa->id,
            'sucursal_id' => $this->sucursal->id,
            'rol_id' => $this->admin->rol_id,
            'email' => $email,
            'password_hash' => 'otraclavevieja',
            'nombre_completo' => 'Cuenta Gemela',
            'activo' => true,
        ]);

        $token = Str::random(64);
        DB::table('recuperaciones_password')->insert([
            'email' => $email,
            'token' => Hash::make($token),
            'creado_en' => now(),
        ]);

        $this->post('/restablecer-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'clavenueva123',
            'password_confirmation' => 'clavenueva123',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect('/login')->assertSessionHas('success');

        // ambas cuentas quedaron con la nueva clave y el token se consumio
        $cuentas = Usuario::where('email', $email)->get();
        $this->assertCount(2, $cuentas);
        $cuentas->each(fn ($u) => $this->assertTrue(Hash::check('clavenueva123', $u->password_hash)));
        $this->assertNull(DB::table('recuperaciones_password')->where('email', $email)->first());

        // y puede loguearse con ella
        $this->post('/login', ['email' => $email, 'password' => 'clavenueva123'])->assertRedirect('/dashboard');
    }

    public function test_rechaza_token_invalido_o_expirado(): void
    {
        $email = $this->admin->email;
        $hashOriginal = $this->admin->password_hash;

        // token equivocado
        DB::table('recuperaciones_password')->insert([
            'email' => $email,
            'token' => Hash::make('token-real'),
            'creado_en' => now(),
        ]);
        $this->post('/restablecer-password', [
            'token' => 'token-falso',
            'email' => $email,
            'password' => 'clavenueva123',
            'password_confirmation' => 'clavenueva123',
        ])->assertSessionHas('error');

        // token correcto pero vencido (mas de 60 minutos)
        DB::table('recuperaciones_password')->where('email', $email)
            ->update(['creado_en' => now()->subMinutes(61)]);
        $this->post('/restablecer-password', [
            'token' => 'token-real',
            'email' => $email,
            'password' => 'clavenueva123',
            'password_confirmation' => 'clavenueva123',
        ])->assertSessionHas('error');

        $this->assertSame($hashOriginal, $this->admin->fresh()->password_hash);
    }
}
