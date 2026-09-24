<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\VerificacionCorreoController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasados los días de gracia sin confirmar el correo, solo queda la pantalla
 * de confirmación (y cerrar sesión). Antes de eso el layout muestra un aviso.
 */
class CorreoVerificado
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario || $usuario->email_verificado_en || $request->routeIs('verificacion.*', 'logout')) {
            return $next($request);
        }

        if ($usuario->creado_en && $usuario->creado_en->addDays(VerificacionCorreoController::DIAS_GRACIA)->isFuture()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Confirma tu correo para seguir usando el sistema.'], 403);
        }

        return redirect()->route('verificacion.aviso')
            ->with('error', 'Confirma tu correo para seguir usando el sistema.');
    }
}
