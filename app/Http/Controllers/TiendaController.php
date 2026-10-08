<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\CatalogoTiendaService;
use App\Support\Tienda;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Catálogo público de una empresa en su propia dirección. No usa sesión ni cookies. */
class TiendaController extends Controller
{
    private const POR_PAGINA = 24;

    /** Páginas de texto de la tienda: su dirección y de qué parte del contenido salen. */
    public const PAGINAS = ['nosotros' => 'nosotros', 'politicas' => 'politicas', 'preguntas-frecuentes' => 'preguntas'];

    /** Cuántos productos del catálogo asoman en la portada, antes del botón para verlos todos. */
    private const EN_PORTADA = 12;

    /** Cuántos productos sugiere el buscador mientras se escribe. */
    private const SUGERENCIAS = 6;

    public function __construct(private readonly CatalogoTiendaService $catalogo) {}

    /** Portada: presentación, destacados y una muestra del catálogo. */
    public function inicio(Request $request): View|RedirectResponse
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);
        $contexto = $this->contexto($request, $empresa, $config);

        // enlaces de antes (/?categoria={id}): van a la dirección nueva de esa categoría
        if ($antigua = $contexto['categorias']->firstWhere('id', (string) $request->query('categoria'))) {
            $resto = http_build_query($request->except('categoria'));

            return redirect($antigua->url.($resto !== '' ? "?{$resto}" : ''), 301);
        }

        // antes la portada también buscaba y paginaba (/?q=urea, /?page=2): eso ahora vive en /catalogo
        if (filled($request->query('q')) || (int) $request->query('page') > 1 || in_array($request->query('orden'), ['menor', 'mayor'], true)) {
            return redirect('/catalogo?'.http_build_query($request->only('q', 'orden', 'page')), 301);
        }

        return $this->listado($request, $config, $contexto, null, portada: true);
    }

    /** /catalogo: todos los productos, con buscador (?q=) y orden. */
    public function catalogo(Request $request): View
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);

        return $this->listado($request, $config, $this->contexto($request, $empresa, $config), null);
    }

    /** /categoria/fertilizantes: el catálogo de una sola categoría. */
    public function categoria(Request $request, string $categoria): View
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);
        $contexto = $this->contexto($request, $empresa, $config);

        $elegida = $contexto['categorias']->firstWhere('slug', $categoria);
        abort_unless($elegida, 404);

        return $this->listado($request, $config, $contexto, $elegida);
    }

    /** Sugerencias del buscador mientras se escribe: los primeros productos que coinciden y cuántos hay en total. */
    public function buscar(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);
        $buscar = trim(mb_substr((string) $request->query('q'), 0, 80));

        if (mb_strlen($buscar) < 2) {
            return response()->json(['productos' => [], 'total' => 0]);
        }

        $consulta = $this->catalogo->buscar($this->catalogo->productos($empresa), $buscar);
        $total = (clone $consulta)->toBase()->getCountForPagination();

        $productos = $consulta->orderBy('productos.nombre')->limit(self::SUGERENCIAS)->get()
            ->map(fn ($p) => $this->catalogo->tarjeta($p, $config))
            ->map(fn (array $t) => [
                'nombre' => $t['nombre'],
                'url' => $t['url'],
                'imagen' => $t['imagen'],
                'precio' => $t['precio'],
                'detalle' => $t['marca'] ?: $t['categoria'],
            ]);

        return response()->json(['productos' => $productos, 'total' => $total])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    private function listado(Request $request, array $config, array $contexto, ?object $categoria, bool $portada = false): View
    {
        $empresa = $this->empresa($request);
        $buscar = $portada ? '' : trim(mb_substr((string) $request->query('q'), 0, 80));
        $orden = $config['mostrar_precios'] && in_array($request->query('orden'), ['menor', 'mayor'], true) ? $request->query('orden') : 'nombre';

        $productos = $this->catalogo->productos($empresa)
            ->when($buscar !== '', fn ($q) => $this->catalogo->buscar($q, $buscar))
            ->when($categoria, fn ($q) => $q->where('productos.categoria_id', $categoria->id))
            // por defecto, primero lo que hay para vender: una primera página llena de "Agotado" espanta
            ->when($orden === 'nombre' && $config['mostrar_stock'], fn ($q) => $q->orderByRaw(
                '(CASE WHEN NOT productos.controla_stock OR COALESCE((SELECT SUM(s.cantidad) FROM stock s WHERE s.producto_id = productos.id), 0) > 0 THEN 0 ELSE 1 END)'
            ))
            ->when($orden === 'menor', fn ($q) => $q->orderBy('precio'))
            ->when($orden === 'mayor', fn ($q) => $q->orderByDesc('precio'))
            ->orderBy('productos.nombre')
            ->paginate($portada ? self::EN_PORTADA : self::POR_PAGINA)
            ->withQueryString()
            ->through(fn ($p) => $this->catalogo->tarjeta($p, $config));

        $filtrando = $buscar !== '' || $categoria !== null;
        $destacados = $portada ? $this->catalogo->destacados($empresa) : null;

        return view('tienda.inicio', [
            ...$contexto,
            'portada' => $portada,
            'buscar' => $buscar,
            'categoria' => $categoria,
            'orden' => $orden,
            'filtrando' => $filtrando,
            'productos' => $productos,
            'destacados' => $destacados ? [
                'origen' => $destacados['origen'],
                'productos' => $destacados['productos']->map(fn ($p) => $this->catalogo->tarjeta($p, $config)),
            ] : null,
        ]);
    }

    /**
     * Enlaces de antes: /producto/urea-46-x-50-kg y, más antiguos, con el código
     * (/producto/P0006/urea-46-x-50-kg). Todos llevan a la dirección nueva del producto.
     */
    public function productoAntiguo(Request $request, string $ref, ?string $nombre = null): RedirectResponse
    {
        $empresa = $this->empresa($request);

        $producto = ($nombre === null ? $this->catalogo->encontrar($empresa, $ref) : null)
            ?? $this->catalogo->encontrarPorCodigo($empresa, $ref);
        abort_unless($producto, 404);

        return redirect($this->catalogo->url($producto), 301);
    }

    /** /catalogo/urea-46-x-50-kg: la página de un producto. */
    public function producto(Request $request, string $ref): View|RedirectResponse
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);

        $producto = $this->catalogo->encontrar($empresa, $ref);

        // por su código o su id solo se abre el que aún no tiene nombre de enlace
        if (! $producto) {
            $producto = $this->catalogo->encontrarPorCodigo($empresa, $ref);
            abort_unless($producto, 404);

            if ($producto->slug) {
                return redirect($this->catalogo->url($producto), 301);
            }
        }

        $tarjeta = $this->catalogo->tarjeta($producto, $config);
        $contexto = $this->contexto($request, $empresa, $config);
        $enlace = rtrim($contexto['tienda']['url'], '/').$tarjeta['url'];

        return view('tienda.producto', [
            ...$contexto,
            'producto' => $tarjeta,
            'categoriaUrl' => $contexto['categorias']->firstWhere('id', $producto->categoria_id)?->url,
            'enlace' => $enlace,
            'pedido' => Tienda::enlaceWhatsapp(
                $config['whatsapp'],
                "Hola, me interesa este producto de {$contexto['tienda']['nombre']}:\n{$producto->nombre} (código {$producto->codigo_interno})\n{$enlace}",
            ),
            'relacionados' => $this->catalogo->relacionados($empresa, $producto)->map(fn ($p) => $this->catalogo->tarjeta($p, $config)),
        ]);
    }

    /** /nosotros, /politicas y /preguntas-frecuentes: lo que el dueño escribió en "Contenido". */
    public function pagina(Request $request, string $pagina): View
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);
        $contenido = $this->paginas($config)[$pagina] ?? null;
        abort_unless($contenido, 404);

        return view('tienda.pagina', [...$this->contexto($request, $empresa, $config), 'pagina' => $contenido]);
    }

    /**
     * Las páginas de texto que la tienda tiene llenas, por nombre:
     * [url, titulo, secciones: [[subtitulo, texto]], preguntas: [[pregunta, respuesta]]].
     */
    private function paginas(array $config): array
    {
        $politicas = array_values(array_filter([
            ['Envíos y entregas', $config['envios']],
            ['Cambios y devoluciones', $config['devoluciones']],
            ['Formas de pago', $config['pagos']],
        ], fn ($seccion) => filled($seccion[1])));

        return array_filter([
            'nosotros' => filled($config['nosotros'])
                ? ['url' => '/nosotros', 'titulo' => 'Sobre nosotros', 'secciones' => [[null, $config['nosotros']]], 'preguntas' => []]
                : null,
            'politicas' => $politicas
                ? ['url' => '/politicas', 'titulo' => 'Envíos, cambios y pagos', 'secciones' => $politicas, 'preguntas' => []]
                : null,
            'preguntas' => $config['preguntas']
                ? ['url' => '/preguntas-frecuentes', 'titulo' => 'Preguntas frecuentes', 'secciones' => [], 'preguntas' => $config['preguntas']]
                : null,
        ]);
    }

    /** Los banners de la portada, cada uno con el enlace al que lleva (si lleva a alguno). */
    private function banners(array $config, Collection $categorias, ?string $whatsapp): array
    {
        return array_map(function (array $banner) use ($categorias, $whatsapp) {
            $enlace = match ($banner['destino'] ?? 'ninguno') {
                'catalogo' => '/catalogo',
                // una categoría que ya no existe (o quedó sin productos) deja el banner sin enlace
                'categoria' => $categorias->firstWhere('id', $banner['valor'] ?? null)?->url,
                'whatsapp' => $whatsapp,
                'url' => $banner['valor'] ?? null,
                default => null,
            };

            return [
                'imagen' => $banner['imagen'] ?? null,
                'titulo' => $banner['titulo'] ?? null,
                'enlace' => $enlace,
                'externo' => $enlace !== null && ! str_starts_with($enlace, '/'),
            ];
        }, array_filter($config['banners'], fn ($b) => filled($b['imagen'] ?? null)));
    }

    /** Mapa del sitio para buscadores: la portada, el catálogo, las categorías y cada producto. */
    public function sitemap(Request $request): Response
    {
        $empresa = $this->empresa($request);
        $base = rtrim((string) Tienda::urlDe($empresa), '/');

        $urls = collect([['loc' => $base.'/', 'lastmod' => null], ['loc' => $base.'/catalogo', 'lastmod' => null]])
            ->concat(collect($this->paginas(Tienda::config($empresa)))->map(fn ($p) => ['loc' => $base.$p['url'], 'lastmod' => null])->values())
            ->concat($this->catalogo->categorias($empresa)->map(fn ($c) => ['loc' => $base.$c->url, 'lastmod' => null]))
            ->concat($this->catalogo->productos($empresa)->orderBy('productos.nombre')->limit(5000)->get()
                ->map(fn ($p) => ['loc' => $base.$this->catalogo->url($p), 'lastmod' => $p->actualizado_en?->toDateString()]));

        return response()->view('tienda.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=utf-8');
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('tienda');
    }

    /** Lo que usan todas las páginas de la tienda: quién es, sus colores y cómo contactarla. */
    private function contexto(Request $request, Empresa $empresa, array $config): array
    {
        $nombre = $empresa->nombre_comercial ?: $empresa->razon_social;
        $categorias = $this->catalogo->categorias($empresa);
        $whatsapp = Tienda::enlaceWhatsapp($config['whatsapp'], "Hola, vi la tienda en línea de {$nombre} y quiero hacer una consulta.");

        return [
            'tienda' => [
                'nombre' => $nombre,
                'inicial' => Str::upper(Str::substr($nombre, 0, 1)),
                'logo' => $empresa->logo_url,
                'favicon' => $config['favicon'],
                'descripcion' => $config['descripcion'],
                'url' => (string) Tienda::urlDe($empresa),
                'colores' => Tienda::colores($config),
                'mostrar_precios' => (bool) $config['mostrar_precios'],
                'productos' => $this->catalogo->total($empresa),
                'anuncio' => $config['anuncio'],
                'portada' => [
                    'estilo' => $config['portada_estilo'],
                    'imagen' => $config['portada_imagen'],
                    'titulo' => $config['portada_titulo'] ?: $nombre,
                    'boton' => $config['portada_boton'] ?: 'Ver catálogo',
                ],
            ],
            // true cuando el dueño está mirando un borrador con su enlace de vista previa
            'previa' => (bool) $request->attributes->get('tienda_previa', false),
            'contactos' => $this->catalogo->contactos($empresa, $config),
            // la barra de categorías y el pie van en todas las páginas
            'categorias' => $categorias,
            'whatsapp' => $whatsapp,
            'banners' => $this->banners($config, $categorias, $whatsapp),
            // páginas de texto que la tienda tiene llenas (para el pie y la portada)
            'paginas' => array_values(array_map(fn ($p) => ['url' => $p['url'], 'titulo' => $p['titulo']], $this->paginas($config))),
            'nosotros' => $config['nosotros'],
        ];
    }
}
