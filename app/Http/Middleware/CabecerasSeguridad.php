<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeceras defensivas para todas las respuestas web. */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $cabeceras = $response->headers;

        // SAMEORIGIN y no DENY: el ticket de impresion directa puede cargarse en un iframe propio
        $cabeceras->set('X-Frame-Options', 'SAMEORIGIN');
        $cabeceras->set('X-Content-Type-Options', 'nosniff');
        $cabeceras->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // camera=(self): el POS escanea codigos de barras con la camara del celular (solo este sitio, nunca iframes ajenos)
        $cabeceras->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $cabeceras->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
