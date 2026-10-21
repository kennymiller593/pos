<?php

namespace App\Http\Middleware;

use App\Support\Medicion;
use App\Support\Tienda;
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
        // las herramientas de medición que el dueño conectó (Analytics, píxeles, Clarity) necesitan sus dominios
        $empresa = $request->attributes->get('tienda');
        $medicion = $empresa && ! $request->attributes->get('tienda_previa') ? Medicion::dominiosCsp(Tienda::config($empresa)) : ['script' => [], 'conexion' => []];
        $respuesta->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            trim("script-src 'self' https://static.cloudflareinsights.com ".implode(' ', $medicion['script'])),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            trim("connect-src 'self' https://cloudflareinsights.com ".implode(' ', $medicion['conexion'])),
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
            $respuesta->headers->set('Cache-Control', 'public, max-age=0, s-maxage=300, stale-while-revalidate=600');
        }

        return $respuesta;
    }
}
