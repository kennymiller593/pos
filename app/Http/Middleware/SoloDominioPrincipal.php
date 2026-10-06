<?php

namespace App\Http\Middleware;

use App\Support\Tienda;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El sistema (login, POS, reportes...) solo se atiende en su propio dominio. En la dirección de
 * una tienda ({slug}.inkanet.pro) esas rutas no existen: ahí solo vive el catálogo público.
 */
class SoloDominioPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Tienda::esHost($request->getHost()), 404);

        return $next($request);
    }
}
