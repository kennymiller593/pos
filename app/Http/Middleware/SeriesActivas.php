<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las series / IMEI son un módulo opcional: la consulta de series solo abre para quien lo activó en Configuración → Empresa. */
class SeriesActivas
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->empresa?->series_activo, 403, 'Activa "Series / IMEI" en Configuración → Empresa para usar esta sección.');

        return $next($request);
    }
}
