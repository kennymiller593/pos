<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RecuperarPassword;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RecuperacionPasswordController extends Controller
{
    private const MINUTOS_VIGENCIA = 60;

    public function solicitar(): Response
    {
        return Inertia::render('Auth/OlvidePassword');
    }

    public function enviar(Request $request): RedirectResponse
    {
        $datos = $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Ingresa tu correo.', 'email.email' => 'El correo no es válido.'],
        );

        $email = mb_strtolower($datos['email']);

        // solo se envia si existe una cuenta activa, pero la respuesta es
        // siempre la misma para no revelar que correos estan registrados
        if (Usuario::where('email', $email)->where('activo', true)->exists()) {
            $token = Str::random(64);

            DB::table('recuperaciones_password')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'creado_en' => now()],
            );

            $url = url('/restablecer-password/'.$token.'?email='.urlencode($email));
            Mail::to($email)->send(new RecuperarPassword($url));
        }

        return back()->with('success', 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja.');
    }

    public function restablecer(Request $request, string $token): Response
    {
        return Inertia::render('Auth/RestablecerPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.required' => 'Ingresa la nueva contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $email = mb_strtolower($datos['email']);

        $fila = DB::table('recuperaciones_password')->where('email', $email)->first();
        $vigente = $fila
            && Hash::check($datos['token'], $fila->token)
            && now()->diffInMinutes($fila->creado_en, true) <= self::MINUTOS_VIGENCIA;

        if (! $vigente) {
            return back()->with('error', 'El enlace no es válido o ya expiró. Solicita uno nuevo.');
        }

        // el mismo correo puede tener cuenta en mas de una empresa: se actualizan todas
        Usuario::where('email', $email)->where('activo', true)->get()
            ->each(function (Usuario $usuario) use ($datos) {
                $usuario->password_hash = $datos['password'];
                $usuario->save();
            });

        DB::table('recuperaciones_password')->where('email', $email)->delete();

        // quien tuviera una sesion robada la pierde (solo posible con sesiones en base de datos)
        if (config('session.driver') === 'database') {
            $ids = Usuario::where('email', $email)->pluck('id');
            DB::table(config('session.table', 'sessions'))->whereIn('user_id', $ids)->delete();
        }

        return redirect('/login')->with('success', 'Contraseña actualizada. Ya puedes ingresar.');
    }
}
