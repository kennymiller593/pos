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
            // la foto es la de cualquiera de sus productos que tenga una
            ->selectRaw('categorias.id, categorias.nombre, COUNT(*) as productos, MAX(productos.imagen_url) as imagen')
            ->toBase()
            ->get();
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

    /** Un producto visible, por su código (o su id cuando el código no sirve para un enlace). */
    public function encontrar(Empresa $empresa, string $referencia): ?Producto
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
        if (! $producto->categoria_id) {
            return collect();
        }

        return $this->productos($empresa)
            ->where('productos.categoria_id', $producto->categoria_id)
            ->whereKeyNot($producto->id)
            ->orderByDesc('productos.destacado')
            ->orderBy('productos.nombre')
            ->limit($cuantos)
            ->get();
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
            'nombre' => $producto->nombre,
            'codigo' => $producto->codigo_interno,
            'marca' => $producto->marca?->nombre,
            'categoria' => $producto->categoria?->nombre,
            'categoria_id' => $producto->categoria_id,
            'imagen' => $producto->imagen_url,
            'descripcion' => $producto->descripcion,
            'destacado' => (bool) $producto->destacado,
            'url' => $this->url($producto),
            'precio' => $config['mostrar_precios'] && $principal ? (float) $principal->precio_venta : null,
            'presentacion' => $principal && $producto->presentaciones->count() > 1 ? $principal->nombre : null,
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

    /** Ruta de la página del producto: /producto/P0006/urea-46-x-50-kg */
    public function url(Producto $producto): string
    {
        // un código con espacios o barras no sirve dentro de un enlace: ahí se usa el id
        $referencia = preg_match('/^[A-Za-z0-9_-]+$/', (string) $producto->codigo_interno) ? $producto->codigo_interno : $producto->id;

        return '/producto/'.$referencia.'/'.(Str::slug($producto->nombre) ?: 'producto');
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

        return [
            'whatsapp' => Tienda::numeroWhatsapp($config['whatsapp']),
            'whatsapp_texto' => $config['whatsapp'],
            'telefono' => $config['telefono'],
            'email' => $config['email'],
            'direccion' => $config['direccion'],
            'horario' => $config['horario'],
            'facebook' => $red($config['facebook'], 'https://www.facebook.com/'),
            'instagram' => $red($config['instagram'], 'https://www.instagram.com/'),
            'tiktok' => $red($config['tiktok'], 'https://www.tiktok.com/@'),
            'locales' => $empresa->sucursales()
                ->where('activo', true)
                ->whereNotNull('direccion')
                ->where('direccion', '!=', '')
                ->orderBy('nombre')
                ->get(['nombre', 'direccion', 'telefono'])
                ->map(fn ($s) => ['nombre' => $s->nombre, 'direccion' => $s->direccion, 'telefono' => $s->telefono])
                ->all(),
        ];
    }
}
