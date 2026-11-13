<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las variantes (modelos con talla, color...) son un módulo opcional: solo entra quien lo activó en Configuración → Empresa. */
class VariantesActivas
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->empresa?->variantes_activo, 403, 'Activa "Variantes (tallas y colores)" en Configuración → Empresa para usar esta sección.');

        return $next($request);
    }
}
