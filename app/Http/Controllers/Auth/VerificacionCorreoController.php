<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\VerificarCorreo;
use App\Models\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Confirmación del correo del usuario que registró la empresa. Hay 3 días de
 * gracia para usar el sistema sin confirmar; después solo se puede reenviar el enlace.
 */
class VerificacionCorreoController extends Controller
{
    public const DIAS_GRACIA = 3;

    public static function enviar(Usuario $usuario): void
    {
        $url = URL::temporarySignedRoute('verificacion.verificar', now()->addDays(self::DIAS_GRACIA), [
            'usuario' => $usuario->id,
            'hash' => sha1($usuario->email),
        ]);

        Mail::to($usuario->email)->send(new VerificarCorreo($url, $usuario->nombre_completo));
    }

    /** Pantalla de "confirma tu correo" (a la que se llega cuando venció la gracia). */
    public function aviso(Request $request): Response|RedirectResponse
    {
        if ($request->user()->email_verificado_en) {
            return redirect('/');
        }

        return Inertia::render('Auth/VerificarCorreo', [
            'email' => $request->user()->email,
        ]);
    }

    public function verificar(Request $request, Usuario $usuario, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature() || ! hash_equals(sha1($usuario->email), $hash)) {
            return redirect('/login')->with('error', 'El enlace de confirmación no es válido o ya venció. Pide uno nuevo desde tu cuenta.');
        }

        if (! $usuario->email_verificado_en) {
            $usuario->forceFill(['email_verificado_en' => now()])->save();
        }

        return redirect('/')->with('success', 'Correo confirmado. ¡Gracias!');
    }

    public function reenviar(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario->email_verificado_en) {
            return redirect('/');
        }

        self::enviar($usuario);

        return back()->with('success', "Te reenviamos el enlace a {$usuario->email}. Revisa tu bandeja (y el spam).");
    }
}
