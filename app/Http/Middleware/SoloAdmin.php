<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SoloAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->loadMissing('rol')->rol?->codigo === 'admin',
            403,
            'Solo un administrador puede acceder a esta sección.',
        );

        return $next($request);
    }
}
