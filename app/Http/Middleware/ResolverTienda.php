<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Services\PortadaTiendaService;
use App\Services\SuscripcionService;
use App\Support\Tienda;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * {slug}.inkanet.pro -> la empresa dueña de esa dirección. La tienda solo se muestra si la empresa
 * tiene el adicional activo, la publicó, sigue activa y tiene su suscripción vigente (o en gracia).
 *
 * Con un enlace de vista previa (?previa=clave) el dueño ve su borrador: lo que tiene en el
 * formulario sin guardar, o su tienda antes de publicarla. Nadie más lo ve.
 */
class ResolverTienda
{
    private const COOKIE = 'tienda_previa';

    public function __construct(
        private readonly SuscripcionService $suscripciones,
        private readonly PortadaTiendaService $portada,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // la tienda llega por su dirección gratuita ({slug}.inkanet.pro) o por su dominio propio (www.agrocampo.com)
        $porDominio = $request->route('dominio') !== null;
        $slug = $porDominio
            ? (string) Empresa::where('tienda_dominio', mb_strtolower($request->getHost()))->value('tienda_slug')
            : mb_strtolower((string) $request->route('tienda'));

        // "Salir de la vista previa": se olvida el borrador y se vuelve a la tienda real
        if ($request->query('previa') === 'salir') {
            return redirect('/')->withCookie(Cookie::create(self::COOKIE, '', 1, '/'));
        }

        // la clave llega en el enlace y luego viaja en una cookie, para poder navegar por la tienda
        $clave = $request->query('previa') ?: $request->cookies->get(self::COOKIE);
        $borrador = $this->portada->vistaPrevia(is_string($clave) ? $clave : null, $slug);

        $empresa = $borrador
            ? Empresa::query()->whereKey($borrador['empresa_id'])->where('tienda_habilitada', true)->where('activo', true)->first()
            : Empresa::query()
                ->where('tienda_slug', $slug)
                ->where('tienda_publicada', true)
                ->where('tienda_habilitada', true) // el adicional lo activa la plataforma
                ->where('activo', true)
                ->first();

        abort_if(! $empresa || ! $this->suscripciones->resumen($empresa)['vigente'], 404);

        if ($porDominio) {
            // el dominio propio solo atiende con su adicional activo y el certificado emitido
            abort_unless(Tienda::dominioActivo($empresa) && $empresa->tienda_dominio === mb_strtolower($request->getHost()), 404);
        } elseif (! $borrador && Tienda::dominioActivo($empresa)) {
            // la dirección gratuita sigue funcionando, pero manda a la propia: una sola dirección para Google y para compartir
            return redirect()->away(Tienda::urlDe($empresa, $request->getRequestUri()), 301);
        }

        if ($borrador) {
            // solo en memoria: esta petición pinta el borrador, nada de esto se guarda
            $empresa->tienda_slug = $slug;
            $empresa->tienda_config = $borrador['config'];
            $request->attributes->set('tienda_previa', true);
        }

        $request->attributes->set('tienda', $empresa);
        // los controladores reciben solo sus propios parametros, y route('tienda.*') ya sabe de que tienda se trata
        $request->route()->forgetParameter($porDominio ? 'dominio' : 'tienda');
        URL::defaults(['tienda' => $slug, 'dominio' => $request->getHost()]);

        $respuesta = $next($request);

        if ($borrador) {
            $respuesta->headers->setCookie(Cookie::create(self::COOKIE, (string) $clave, now()->addMinutes(PortadaTiendaService::MINUTOS_PREVIA), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
            // un borrador no se guarda en cachés ni lo indexa un buscador
            $respuesta->headers->set('Cache-Control', 'no-store, private');
            $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $respuesta;
    }
}
