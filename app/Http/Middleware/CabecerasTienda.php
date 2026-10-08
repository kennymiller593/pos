<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las mismas cabeceras defensivas del sistema, para la tienda en línea. La tienda corre fuera del
 * grupo web (sin sesión), y al excluir ese grupo Laravel excluye también CabecerasSeguridad y
 * cualquier clase que la herede: por eso esta la usa por dentro en vez de extenderla.
 */
class CabecerasTienda
{
    public function __construct(private readonly CabecerasSeguridad $cabeceras) {}

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $this->cabeceras->handle($request, $next);

        // la tienda no usa cámara ni nada del sistema: permisos cerrados y una política de contenido propia
        // (todo es propio del sitio; las fotos pueden venir de la nube; Cloudflare puede inyectar su medición)
        $respuesta->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $respuesta->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://static.cloudflareinsights.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "connect-src 'self' https://cloudflareinsights.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        // La tienda no usa sesión ni cookies: sus páginas pueden guardarse en la caché de borde (Cloudflare)
        // un minuto, y servirse al instante mientras se renuevan. El borrador del dueño (vista previa) y las
        // respuestas JSON del buscador no. Al guardar cambios, además, se purga (CloudflareService).
        $cacheable = $request->isMethod('GET')
            && $respuesta->getStatusCode() === 200
            && ! $request->attributes->get('tienda_previa')
            && ! $respuesta->headers->has('Set-Cookie')
            && ! str_contains((string) $respuesta->headers->get('Content-Type'), 'json');

        if ($cacheable) {
            $respuesta->headers->set('Cache-Control', 'public, max-age=0, s-maxage=60, stale-while-revalidate=600');
        }

        return $respuesta;
    }
}
