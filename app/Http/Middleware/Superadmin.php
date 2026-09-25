<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Panel de la plataforma: solo usuarios marcados con superadmin:asignar. */
class Superadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->es_superadmin, 403, 'Solo el administrador de la plataforma puede entrar aquí.');

        return $next($request);
    }
}
