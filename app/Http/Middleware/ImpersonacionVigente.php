<?php

namespace App\Http\Middleware;

use App\Services\ImpersonacionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** La sesión "entrar como" expira sola: pasado el plazo se vuelve a la plataforma. */
class ImpersonacionVigente
{
    public function __construct(private readonly ImpersonacionService $impersonacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $datos = $this->impersonacion->activa($request);

        if ($datos && $this->impersonacion->expirada($datos)) {
            $superadmin = $this->impersonacion->salir($request, 'expiró');

            return $superadmin
                ? redirect()->route('admin.empresas.index')->with('error', 'La sesión como '.$datos['usuario'].' expiró a las '.ImpersonacionService::HORAS.' horas. Volviste a la plataforma.')
                : redirect('/login');
        }

        return $next($request);
    }
}
