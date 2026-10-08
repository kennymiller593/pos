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
        $host = $request->getHost();

        // con dominios propios, cualquier host ajeno al sistema es (o pretende ser) una tienda
        abort_if(Tienda::esHost($host) || (Tienda::conDominiosPropios() && ! Tienda::esHostDeLaApp($host)), 404);

        return $next($request);
    }
}
