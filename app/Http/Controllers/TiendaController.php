<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\CatalogoTiendaService;
use App\Support\Tienda;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Catálogo público de una empresa en su propia dirección. No usa sesión ni cookies. */
class TiendaController extends Controller
{
    private const POR_PAGINA = 24;

    public function __construct(private readonly CatalogoTiendaService $catalogo) {}

    /** Portada: destacados y catálogo; con ?q= o ?categoria= pasa a ser el resultado de la búsqueda. */
    public function inicio(Request $request): View
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);

        $buscar = trim(mb_substr((string) $request->query('q'), 0, 80));
        $categorias = $this->catalogo->categorias($empresa);
        $categoria = $categorias->firstWhere('id', (string) $request->query('categoria'));
        $orden = $config['mostrar_precios'] && in_array($request->query('orden'), ['menor', 'mayor'], true) ? $request->query('orden') : 'nombre';

        $productos = $this->catalogo->productos($empresa)
            ->when($buscar !== '', fn ($q) => $this->catalogo->buscar($q, $buscar))
            ->when($categoria, fn ($q) => $q->where('productos.categoria_id', $categoria->id))
            ->when($orden === 'menor', fn ($q) => $q->orderBy('precio'))
            ->when($orden === 'mayor', fn ($q) => $q->orderByDesc('precio'))
            ->orderBy('productos.nombre')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn ($p) => $this->catalogo->tarjeta($p, $config));

        $filtrando = $buscar !== '' || $categoria !== null;
        $destacados = $filtrando || $productos->currentPage() > 1 ? null : $this->catalogo->destacados($empresa);

        return view('tienda.inicio', [
            ...$this->contexto($empresa, $config),
            'buscar' => $buscar,
            'categorias' => $categorias,
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

    public function producto(Request $request, string $ref): View
    {
        $empresa = $this->empresa($request);
        $config = Tienda::config($empresa);

        $producto = $this->catalogo->encontrar($empresa, $ref);
        abort_unless($producto, 404);

        $tarjeta = $this->catalogo->tarjeta($producto, $config);
        $contexto = $this->contexto($empresa, $config);
        $enlace = rtrim($contexto['tienda']['url'], '/').$tarjeta['url'];

        return view('tienda.producto', [
            ...$contexto,
            'producto' => $tarjeta,
            'enlace' => $enlace,
            'pedido' => Tienda::enlaceWhatsapp(
                $config['whatsapp'],
                "Hola, me interesa este producto de {$contexto['tienda']['nombre']}:\n{$producto->nombre} (código {$producto->codigo_interno})\n{$enlace}",
            ),
            'relacionados' => $this->catalogo->relacionados($empresa, $producto)->map(fn ($p) => $this->catalogo->tarjeta($p, $config)),
        ]);
    }

    /** Mapa del sitio para buscadores: la portada y cada producto. */
    public function sitemap(Request $request): Response
    {
        $empresa = $this->empresa($request);
        $base = rtrim((string) Tienda::url($empresa->tienda_slug), '/');

        $urls = $this->catalogo->productos($empresa)->orderBy('productos.nombre')->limit(5000)->get()
            ->map(fn ($p) => ['loc' => $base.$this->catalogo->url($p), 'lastmod' => $p->actualizado_en?->toDateString()])
            ->prepend(['loc' => $base.'/', 'lastmod' => null]);

        return response()->view('tienda.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=utf-8');
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('tienda');
    }

    /** Lo que usan todas las páginas de la tienda: quién es, sus colores y cómo contactarla. */
    private function contexto(Empresa $empresa, array $config): array
    {
        $nombre = $empresa->nombre_comercial ?: $empresa->razon_social;

        return [
            'tienda' => [
                'nombre' => $nombre,
                'inicial' => Str::upper(Str::substr($nombre, 0, 1)),
                'logo' => $empresa->logo_url,
                'descripcion' => $config['descripcion'],
                'url' => (string) Tienda::url($empresa->tienda_slug),
                'colores' => config('tienda.colores')[$config['color']],
                'mostrar_precios' => (bool) $config['mostrar_precios'],
            ],
            'contactos' => $this->catalogo->contactos($empresa, $config),
            'whatsapp' => Tienda::enlaceWhatsapp($config['whatsapp'], "Hola, vi la tienda en línea de {$nombre} y quiero hacer una consulta."),
        ];
    }
}
