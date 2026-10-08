<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\ComprobanteDetalle;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Support\Tienda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lo que la tienda en línea muestra de una empresa: solo productos activos, marcados para la
 * tienda y con al menos una presentación a la venta. Nunca expone costos ni cantidades en stock.
 */
class CatalogoTiendaService
{
    public const DESTACADOS = 8;

    /** Productos visibles en la tienda, con su precio de lista y lo necesario para pintarlos. */
    public function productos(Empresa $empresa): Builder
    {
        $presentacionPrincipal = fn (string $columna) => ProductoPresentacion::query()
            ->select($columna)
            ->whereColumn('producto_id', 'productos.id')
            ->where('activo', true)
            ->orderByDesc('es_default')
            ->orderBy('factor_conversion')
            ->limit(1);

        return Producto::query()
            ->select('productos.*')
            ->where('productos.empresa_id', $empresa->id)
            ->where('productos.activo', true)
            ->where('productos.en_tienda', true)
            ->whereHas('presentaciones', fn ($q) => $q->where('activo', true))
            ->addSelect(['precio' => $presentacionPrincipal('precio_venta')])
            ->withSum('stock as stock_total', 'cantidad')
            ->with([
                'marca:id,nombre',
                'categoria:id,nombre',
                'presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('factor_conversion'),
            ]);
    }

    /** El catálogo de ubigeos viene en mayúsculas y sin tildes: los nombres más comunes, bien escritos. */
    private const TILDES = [
        'HUANUCO' => 'Huánuco', 'ANCASH' => 'Áncash', 'APURIMAC' => 'Apurímac', 'JUNIN' => 'Junín', 'SAN MARTIN' => 'San Martín',
        'CAÑETE' => 'Cañete', 'CANETE' => 'Cañete', 'JAEN' => 'Jaén', 'TARAPOTO' => 'Tarapoto', 'CHACHAPOYAS' => 'Chachapoyas',
        'HUANCAYO' => 'Huancayo', 'LEONCIO PRADO' => 'Leoncio Prado', 'RUPA-RUPA' => 'Rupa-Rupa', 'SATIPO' => 'Satipo',
        'CORONEL PORTILLO' => 'Coronel Portillo', 'CALLERIA' => 'Callería', 'MARISCAL NIETO' => 'Mariscal Nieto', 'SANCHEZ CARRION' => 'Sánchez Carrión',
        'HUAMANGA' => 'Huamanga', 'BAGUA' => 'Bagua', 'ANDAHUAYLAS' => 'Andahuaylas', 'AMARILIS' => 'Amarilis', 'PILLCO MARCA' => 'Pillco Marca',
    ];

    /** Cada palabra buscada debe aparecer en el nombre, la marca, el código o el código de barras. */
    public function buscar(Builder $consulta, string $texto): Builder
    {
        $palabras = collect(preg_split('/\s+/', trim($texto)))->filter()->take(6);

        foreach ($palabras as $palabra) {
            // % y _ son comodines de LIKE: se buscan como texto
            $patron = '%'.addcslashes($palabra, '%_\\').'%';

            $consulta->where(fn ($q) => $q
                ->where('productos.nombre', 'ilike', $patron)
                ->orWhere('productos.codigo_interno', 'ilike', $patron)
                ->orWhereHas('marca', fn ($m) => $m->where('nombre', 'ilike', $patron))
                ->orWhereHas('presentaciones', fn ($p) => $p->where('activo', true)->where('codigo_barras', $palabra)));
        }

        return $consulta;
    }

    /** Cuántos productos se ven en la tienda. */
    public function total(Empresa $empresa): int
    {
        return Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->where('en_tienda', true)
            ->whereHas('presentaciones', fn ($q) => $q->where('activo', true))
            ->count();
    }

    /** Categorías que tienen algo visible en la tienda, con cuántos productos y una foto que las represente. */
    public function categorias(Empresa $empresa): Collection
    {
        return Categoria::query()
            ->where('categorias.empresa_id', $empresa->id)
            ->join('productos', 'productos.categoria_id', '=', 'categorias.id')
            ->where('productos.activo', true)
            ->where('productos.en_tienda', true)
            ->whereNull('productos.eliminado_en')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('producto_presentaciones')
                ->whereColumn('producto_presentaciones.producto_id', 'productos.id')
                ->where('producto_presentaciones.activo', true))
            ->groupBy('categorias.id', 'categorias.nombre')
            ->orderBy('categorias.nombre')
            // dos categorías con el mismo nombre: siempre en el mismo orden, para que su dirección no se alterne
            ->orderBy('categorias.id')
            // la foto es la de cualquiera de sus productos que tenga una
            ->selectRaw('categorias.id, categorias.nombre, COUNT(*) as productos, MAX(productos.imagen_url) as imagen')
            ->toBase()
            ->get()
            ->pipe(fn (Collection $categorias) => $this->conEnlace($categorias));
    }

    /**
     * Le pone a cada categoría su dirección amigable: /categoria/fertilizantes.
     * Si dos categorías dan el mismo nombre de enlace, la segunda lleva "-2".
     */
    private function conEnlace(Collection $categorias): Collection
    {
        $usados = [];

        return $categorias->each(function ($categoria) use (&$usados) {
            $base = Tienda::slug($categoria->nombre) ?: 'categoria';
            $slug = $base;
            for ($i = 2; isset($usados[$slug]); $i++) {
                $slug = "{$base}-{$i}";
            }
            $usados[$slug] = true;

            $categoria->slug = $slug;
            $categoria->url = "/categoria/{$slug}";
        });
    }

    /**
     * Productos destacados: los que el dueño marcó. Si no marcó ninguno, los más vendidos de los
     * últimos 90 días; y si la tienda es nueva y aún no vende, los más recientes.
     *
     * @return array{origen: string, productos: Collection<int, Producto>}
     */
    public function destacados(Empresa $empresa): array
    {
        $marcados = $this->productos($empresa)->where('productos.destacado', true)->orderBy('productos.nombre')->limit(self::DESTACADOS)->get();

        if ($marcados->isNotEmpty()) {
            return ['origen' => 'elegidos', 'productos' => $marcados];
        }

        // el calculo recorre las ventas: se guarda unos minutos para no repetirlo en cada visita
        $ids = Cache::remember("tienda:{$empresa->id}:mas-vendidos", 600, fn () => ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->where('comprobantes.empresa_id', $empresa->id)
            ->where('comprobantes.estado', 'emitido')
            ->where('comprobantes.tipo_comprobante_codigo', '!=', '07')
            ->where('comprobantes.fecha_emision', '>=', now()->subDays(90)->toDateString())
            ->groupBy('comprobante_detalles.producto_id')
            ->orderByRaw('SUM(comprobante_detalles.total) desc')
            ->limit(self::DESTACADOS * 3)
            ->pluck('comprobante_detalles.producto_id')
            ->all());

        if ($ids !== []) {
            $vendidos = $this->productos($empresa)->whereIn('productos.id', $ids)->get()
                ->sortBy(fn (Producto $p) => array_search($p->id, $ids, true))
                ->take(self::DESTACADOS)
                ->values();

            if ($vendidos->isNotEmpty()) {
                return ['origen' => 'vendidos', 'productos' => $vendidos];
            }
        }

        return [
            'origen' => 'nuevos',
            'productos' => $this->productos($empresa)->orderByDesc('productos.creado_en')->limit(self::DESTACADOS)->get(),
        ];
    }

    /** Un producto visible, por su nombre de enlace: /catalogo/urea-46-x-50-kg */
    public function encontrar(Empresa $empresa, string $slug): ?Producto
    {
        return $this->productos($empresa)->where('productos.slug', $slug)->orderBy('productos.id')->first();
    }

    /**
     * Un producto visible por su código o su id: así eran los enlaces antes
     * (/producto/P0006/urea-46-x-50-kg) y hay que seguir encontrándolos.
     */
    public function encontrarPorCodigo(Empresa $empresa, string $referencia): ?Producto
    {
        return $this->productos($empresa)
            ->where(fn ($q) => $q
                ->where('productos.codigo_interno', $referencia)
                ->when(Str::isUuid($referencia), fn ($w) => $w->orWhere('productos.id', $referencia)))
            ->first();
    }

    /** Otros productos de la misma categoría, para seguir mirando. */
    public function relacionados(Empresa $empresa, Producto $producto, int $cuantos = 4): Collection
    {
        // "Urea ..." junto a otras ureas antes que el vecino alfabético
        $prefijo = preg_match('/^(\p{L}{3,})/u', trim($producto->nombre), $m) ? addcslashes($m[1], '%_\\').'%' : null;

        $relacionados = $producto->categoria_id
            ? $this->productos($empresa)
                ->where('productos.categoria_id', $producto->categoria_id)
                ->whereKeyNot($producto->id)
                ->when($prefijo, fn ($q) => $q->orderByRaw('CASE WHEN productos.nombre ILIKE ? THEN 0 ELSE 1 END', [$prefijo]))
                ->orderByDesc('productos.destacado')
                ->orderBy('productos.nombre')
                ->limit($cuantos)
                ->get()
            : $this->productos($empresa)->whereRaw('false')->get();

        // en una categoría chica se completa con productos de nombre parecido ("Urea ..."), aunque sean de otra
        if ($relacionados->count() < $cuantos && $prefijo) {
            $parecidos = $this->productos($empresa)
                ->where('productos.nombre', 'ilike', $prefijo)
                ->whereKeyNot($producto->id)
                ->whereNotIn('productos.id', $relacionados->modelKeys())
                ->orderBy('productos.nombre')
                ->limit($cuantos - $relacionados->count())
                ->get();
            $relacionados = $relacionados->concat($parecidos);
        }

        return $relacionados;
    }

    /**
     * Datos de un producto para la vista. El precio solo sale si la tienda los muestra,
     * y la disponibilidad es sí o no: nunca cuántas unidades hay.
     */
    public function tarjeta(Producto $producto, array $config): array
    {
        $principal = $producto->presentaciones->first();

        return [
            'id' => $producto->id,
            'nombre' => Tienda::nombreVisible($producto->nombre, $config),
            'codigo' => $producto->codigo_interno,
            'marca' => $producto->marca?->nombre,
            'categoria' => $producto->categoria?->nombre,
            'categoria_id' => $producto->categoria_id,
            'imagen' => $producto->imagen_url,
            'descripcion' => $producto->descripcion,
            'destacado' => (bool) $producto->destacado,
            'url' => $this->url($producto),
            'precio' => $precio = $config['mostrar_precios'] && $principal ? (float) $principal->precio_venta : null,
            'presentacion' => $presentacion = $principal && $producto->presentaciones->count() > 1 ? $principal->nombre : null,
            // pedir solo este producto por WhatsApp (null si la tienda no tiene WhatsApp)
            'pedido' => Tienda::enlaceWhatsapp($config['whatsapp'], "Hola, quiero pedir este producto:\n{$producto->nombre} (código {$producto->codigo_interno})"),
            // lo que guarda el pedido del cliente al tocar "Añadir"
            'para_pedido' => [
                'id' => (string) $producto->id,
                'nombre' => $producto->nombre,
                'codigo' => $producto->codigo_interno,
                'unidad' => $presentacion,
                'precio' => $precio,
                'imagen' => $producto->imagen_url,
                'url' => $this->url($producto),
            ],
            'disponible' => $config['mostrar_stock']
                ? (! $producto->controla_stock || (float) $producto->stock_total > 0)
                : null,
            'presentaciones' => $producto->presentaciones->map(fn (ProductoPresentacion $p) => [
                'nombre' => $p->nombre,
                'precio' => $config['mostrar_precios'] ? (float) $p->precio_venta : null,
                'mayorista' => $config['mostrar_precios'] && $p->precio_mayorista !== null && (float) $p->cantidad_mayorista > 0
                    ? ['precio' => (float) $p->precio_mayorista, 'desde' => (float) $p->cantidad_mayorista]
                    : null,
            ])->all(),
        ];
    }

    /** Ruta de la página del producto: /catalogo/urea-46-x-50-kg */
    public function url(Producto $producto): string
    {
        // un producto que aún no tiene nombre de enlace se sigue abriendo por su id
        return '/catalogo/'.($producto->slug ?: $producto->id);
    }

    /** Cuántos productos se ven en la tienda y cuántos están destacados (para la pantalla de configuración). */
    public function resumen(Empresa $empresa): array
    {
        $base = Producto::query()->where('empresa_id', $empresa->id)->where('activo', true);

        return [
            'visibles' => (clone $base)->where('en_tienda', true)->count(),
            'ocultos' => (clone $base)->where('en_tienda', false)->count(),
            'destacados' => (clone $base)->where('en_tienda', true)->where('destacado', true)->count(),
            'sin_foto' => (clone $base)->where('en_tienda', true)->whereNull('imagen_url')->count(),
        ];
    }

    /** Contactos de la tienda: lo que configuró el dueño, más sus locales con dirección. */
    public function contactos(Empresa $empresa, array $config): array
    {
        $red = fn (?string $valor, string $base) => filled($valor)
            ? (Str::startsWith($valor, ['http://', 'https://']) ? $valor : $base.ltrim(trim($valor), '@/'))
            : null;

        // sin enlace propio, el mapa se busca por el texto de la dirección
        $buscarEnMapa = fn (string $direccion) => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($direccion);
        // "Jr. San Martín N° 1638-Huánuco" y "jr san martin n 1638 huanuco" son el mismo lugar
        $igualar = fn (string $direccion) => preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower($direccion)));

        // la primera dirección es el local principal: usa el enlace de Google Maps que pegó el dueño
        $direcciones = collect(Tienda::lineas($config['direccion']))->values()->map(fn (string $texto, int $i) => [
            'texto' => $texto,
            'mapa' => $i === 0 && filled($config['mapa_url']) ? $config['mapa_url'] : $buscarEnMapa($texto),
        ]);
        $yaListadas = $direcciones->map(fn ($d) => $igualar($d['texto']))->all();
        $horarios = Tienda::lineas($config['horario']);

        // distrito y departamento del local principal (la primera sucursal activa con ubigeo)
        $ubigeo = $empresa->sucursales()->where('activo', true)->whereNotNull('ubigeo')->orderBy('nombre')->first()?->ubigeoInfo;
        $titulo = fn (?string $t) => $t ? (self::TILDES[mb_strtoupper($t)] ?? mb_convert_case(mb_strtolower($t), MB_CASE_TITLE, 'UTF-8')) : null;

        return [
            'whatsapp' => Tienda::numeroWhatsapp($config['whatsapp']),
            'whatsapp_texto' => $config['whatsapp'],
            'telefono' => $config['telefono'],
            'email' => $config['email'],
            // el local principal (para la franja superior y los datos del negocio) y todos los que anotó
            'direccion' => $direcciones->first()['texto'] ?? null,
            'mapa' => $direcciones->first()['mapa'] ?? null,
            'direcciones' => $direcciones->all(),
            // en una línea para donde hay poco espacio, y por separado para listarlos
            'horario' => $horarios ? implode(' · ', $horarios) : null,
            'horarios' => $horarios,
            // para los datos estructurados del negocio
            'horario_estructurado' => Tienda::horarioEstructurado($horarios),
            'ciudad' => $titulo($ubigeo?->distrito),
            'provincia' => $titulo($ubigeo?->provincia),
            'region' => $titulo($ubigeo?->departamento),
            'coordenadas' => $config['mapa_lat'] && $config['mapa_lng'] ? [(float) $config['mapa_lat'], (float) $config['mapa_lng']] : null,
            'facebook' => $red($config['facebook'], 'https://www.facebook.com/'),
            'instagram' => $red($config['instagram'], 'https://www.instagram.com/'),
            'tiktok' => $red($config['tiktok'], 'https://www.tiktok.com/@'),
            'locales' => $empresa->sucursales()
                ->where('activo', true)
                ->whereNotNull('direccion')
                ->where('direccion', '!=', '')
                ->orderBy('nombre')
                ->get(['nombre', 'direccion', 'telefono'])
                // una sucursal cuya dirección ya está escrita arriba no se repite
                ->reject(fn ($s) => in_array($igualar($s->direccion), $yaListadas, true))
                ->map(fn ($s) => ['nombre' => $s->nombre, 'direccion' => $s->direccion, 'telefono' => $s->telefono, 'mapa' => $buscarEnMapa($s->direccion)])
                ->values()
                ->all(),
        ];
    }
}
