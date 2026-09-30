<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlataformaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Cuenta del superadmin: mover el acceso de plataforma a otro correo. */
class CuentaController extends Controller
{
    public function index(Request $request): Response
    {
        $usuario = $request->user()->loadMissing('empresa:id,razon_social,nombre_comercial');

        return Inertia::render('Admin/Cuenta/Index', [
            'cuenta' => [
                'nombre' => $usuario->nombre_completo,
                'email' => $usuario->email,
                'empresa' => $usuario->empresa->nombre_comercial ?: $usuario->empresa->razon_social,
            ],
        ]);
    }

    public function migrar(Request $request, PlataformaService $plataforma): RedirectResponse
    {
        $datos = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'nombre_completo.required' => 'Ingresa el nombre.',
            'email.required' => 'Ingresa el correo nuevo.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Ese correo ya está en uso.',
            'password.required' => 'Ingresa una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $nuevo = $plataforma->migrarSuperadmin($request->user(), $datos['email'], $datos['nombre_completo'], $datos['password']);

        // la cuenta actual ya no es de plataforma: se cierra la sesión y se entra con la nueva
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('success', "Listo. Entra con {$nuevo->email} para administrar la plataforma; tu cuenta anterior sigue siendo la de tu empresa.");
    }
}
