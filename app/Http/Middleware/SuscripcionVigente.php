<?php

namespace App\Http\Middleware;

use App\Services\SuscripcionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una empresa desactivada no entra; una empresa sin suscripción vigente solo
 * puede ver la pantalla de suscripción (y cerrar sesión).
 */
class SuscripcionVigente
{
    public function __construct(private readonly SuscripcionService $suscripciones) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return $next($request);
        }

        $empresa = $usuario->empresa;

        if (! $empresa || ! $empresa->activo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('error', 'La cuenta de tu empresa está desactivada. Escríbenos para reactivarla.');
        }

        if ($request->routeIs('suscripcion.*', 'logout') || $this->suscripciones->vigente($empresa)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Tu suscripción venció. Renueva tu plan para seguir operando.'], 402);
        }

        return redirect()->route('suscripcion.index')
            ->with('error', 'Tu suscripción venció. Renueva tu plan para seguir operando.');
    }
}
