<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Services\SuscripcionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * {slug}.inkanet.pro -> la empresa dueña de esa dirección. La tienda solo se muestra si la empresa
 * la publicó, sigue activa y tiene su suscripción vigente (o en los días de gracia).
 */
class ResolverTienda
{
    public function __construct(private readonly SuscripcionService $suscripciones) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = mb_strtolower((string) $request->route('tienda'));

        $empresa = Empresa::query()
            ->where('tienda_slug', $slug)
            ->where('tienda_publicada', true)
            ->where('activo', true)
            ->first();

        abort_if(! $empresa || ! $this->suscripciones->resumen($empresa)['vigente'], 404);

        $request->attributes->set('tienda', $empresa);
        // los controladores reciben solo sus propios parametros, y route('tienda.*') ya sabe de que tienda se trata
        $request->route()->forgetParameter('tienda');
        URL::defaults(['tienda' => $slug]);

        return $next($request);
    }
}
