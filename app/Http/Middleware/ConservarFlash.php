<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las consultas de fondo (campanita, estado SUNAT) no deben gastar el aviso que dejó la pantalla.
 * El aviso de una venta vive en la sesión solo hasta la siguiente petición: si la campanita se cuela
 * entre el POST y la recarga del POS, el POS no ve la confirmación, el carrito se queda y se cobra dos veces.
 */
class ConservarFlash
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return $next($request);
    }
}
