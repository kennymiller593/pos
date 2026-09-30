<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El superadmin es una cuenta exclusiva de la plataforma: solo ve /admin (empresas y planes).
 * Cualquier otra pantalla (POS, compras, dashboard...) lo devuelve al panel.
 */
class SoloPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario?->es_superadmin || $request->is('admin', 'admin/*', 'logout', 'verificar-correo*')) {
            return $next($request);
        }

        // llamadas de datos (notificaciones, búsquedas): sin redirección, solo se niegan
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'La cuenta de plataforma no opera empresas.');
        }

        return redirect()->route('admin.empresas.index');
    }
}
