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
        return $this->cabeceras->handle($request, $next);
    }
}
