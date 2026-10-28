<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una pestaña dibujada para un usuario no actúa en nombre de otro. La sesión del navegador es una sola:
 * si en otra pestaña entró el segundo correo del negocio (p. ej. el de la otra sucursal), la pestaña vieja
 * sigue mostrando al usuario anterior pero ya enviaría todo como el nuevo (una compra "de Tarapaca" que
 * entra a Principal). Cada petición Inertia trae el usuario de su pestaña (X-Usuario, ver app.js); si no
 * es el de la sesión, no se procesa: la pestaña se recarga completa con el usuario actual y un aviso.
 */
class SesionCoherente
{
    public function handle(Request $request, Closure $next): Response
    {
        $dePestana = $request->header('X-Usuario');
        $usuario = $request->user();

        if (! $request->header('X-Inertia') || ! $dePestana || ! $usuario || $dePestana === $usuario->id) {
            return $next($request);
        }

        $request->session()->flash('error', "Esta pestaña estaba abierta con otro usuario; la sesión ahora es de {$usuario->nombre_completo}. No se registró nada: revisa la sucursal y vuelve a intentarlo.");

        // recarga completa de la página en la que estaba (o la pedida, si solo navegaba)
        $destino = $request->isMethod('GET') ? $request->fullUrl() : (string) $request->headers->get('referer');
        if (! Str::startsWith($destino, $request->getSchemeAndHttpHost())) {
            $destino = route('inicio');
        }

        return Inertia::location($destino);
    }
}
