<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las mezclas y preparados son un módulo opcional: solo entra quien lo activó en Configuración → Empresa. */
class MezclasActivas
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->empresa?->mezclas_activo, 403, 'Activa "Mezclas y preparados" en Configuración → Empresa para usar esta sección.');

        return $next($request);
    }
}
