<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\ComprobanteDetalle;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\ProductoPresentacion;
use App\Services\BusquedaProductos;
use App\Support\Tienda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que la tienda en línea muestra de una empresa: solo productos activos, marcados para la
 * tienda y con al menos una presentación a la venta. Nunca expone costos ni cantidades en stock.
 */
class CatalogoTiendaService
{
    public const DESTACADOS = 8;

    /** Lo que identifica a una tarjeta del catálogo: el modelo si el producto es una variante; si no, el producto. */
    private const SQL_TARJETA = 'COALESCE(productos.grupo_id, productos.id)';

    /** 0 si hay para vender, 1 si está agotado (mismo criterio que el orden "primero lo disponible"). */
    private const SQL_AGOTADO = '(CASE WHEN NOT productos.controla_stock OR COALESCE((SELECT SUM(s.cantidad) FROM stock s WHERE s.producto_id = productos.id), 0) > 0 THEN 0 ELSE 1 END)';

    /** El precio de lista de un producto (el de su presentación principal), para la tabla con alias %s. */
    private const SQL_PRECIO = '(SELECT pp.precio_venta FROM producto_presentaciones pp WHERE pp.producto_id = %s.id AND pp.activo ORDER BY pp.es_default DESC, pp.factor_conversion LIMIT 1)';

    /** ¿La tienda de cada empresa agrupa variantes? Se pregunta una vez por petición (la empresa se resuelve de nuevo en cada una). */
    private ?\WeakMap $conModelos = null;

    /** Productos visibles en la tienda, con su precio de lista y lo necesario para pintarlos. */
    public function productos(Empresa $empresa): Builder
    {
        return $this->visibles((string) $empresa->id);
    }

    private function visibles(string $empresaId): Builder
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
            ->where('productos.empresa_id', $empresaId)
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

    /**
     * Deja una sola tarjeta por modelo (Polo básico en tallas y colores): de las variantes que pasan los
     * filtros ya puestos en la consulta queda la representativa, la primera con stock en el orden de sus
     * combinaciones (o la primera, si ninguna tiene). Se aplica después de filtrar y antes de ordenar y
     * paginar, así los totales y las páginas cuentan modelos. Una tienda sin variantes no cambia en nada.
     *
     * Con $estable no se mira el stock: queda siempre la primera variante visible. Es la dirección que se da
     * a los buscadores (sitemap y canónica), que no debe cambiar cada vez que una talla se agota.
     */
    public function porModelo(Empresa $empresa, Builder $consulta, bool $estable = false): Builder
    {
        if (! $this->tieneModelos($empresa)) {
            return $consulta;
        }

        $orden = ($estable ? '' : self::SQL_AGOTADO.', ').'productos.variante_orden NULLS LAST, productos.nombre, productos.id';
        $puestos = (clone $consulta)->toBase()->reorder()
            ->select('productos.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.self::SQL_TARJETA.' ORDER BY '.$orden.') AS puesto');

        return $consulta->whereIn('productos.id', DB::query()->fromSub($puestos, 'v')->where('v.puesto', 1)->select('v.id'));
    }

    /**
     * ¿La tienda agrupa variantes en modelos? Solo si la empresa tiene el módulo Variantes encendido (con él
     * apagado las variantes se ven como productos sueltos, igual que en el POS) y hay alguna a la vista.
     * Con el módulo apagado no se consulta nada.
     */
    public function tieneModelos(Empresa $empresa): bool
    {
        if (! $empresa->variantes_activo) {
            return false;
        }
        $this->conModelos ??= new \WeakMap;

        return $this->conModelos[$empresa] ??= $this->visibles((string) $empresa->id)->whereNotNull('productos.grupo_id')->toBase()->exists();
    }

    /**
     * Ordena por precio. Un modelo se ordena por su precio más bajo (el "Desde" de su tarjeta), no por el de
     * la variante que lo representa. Sin modelos, por el precio de lista de siempre.
     */
    public function ordenarPorPrecio(Empresa $empresa, Builder $consulta, bool $deMayorAMenor = false): Builder
    {
        if (! $this->tieneModelos($empresa)) {
            return $deMayorAMenor ? $consulta->orderByDesc('precio') : $consulta->orderBy('precio');
        }

        $delModelo = '(SELECT MIN('.sprintf(self::SQL_PRECIO, 'h').') FROM productos h WHERE h.grupo_id = productos.grupo_id'
            .' AND h.empresa_id = productos.empresa_id AND h.activo AND h.en_tienda AND h.eliminado_en IS NULL)';

        return $consulta
            ->selectRaw('COALESCE('.$delModelo.', '.sprintf(self::SQL_PRECIO, 'productos').') AS precio_orden')
            ->orderBy('precio_orden', $deMayorAMenor ? 'desc' : 'asc');
    }

    /** El catálogo de ubigeos viene en mayúsculas y sin tildes: los nombres más comunes, bien escritos. */
    private const TILDES = [
        'HUANUCO' => 'Huánuco', 'ANCASH' => 'Áncash', 'APURIMAC' => 'Apurímac', 'JUNIN' => 'Junín', 'SAN MARTIN' => 'San Martín',
        'CAÑETE' => 'Cañete', 'CANETE' => 'Cañete', 'JAEN' => 'Jaén', 'TARAPOTO' => 'Tarapoto', 'CHACHAPOYAS' => 'Chachapoyas',
        'HUANCAYO' => 'Huancayo', 'LEONCIO PRADO' => 'Leoncio Prado', 'RUPA-RUPA' => 'Rupa-Rupa', 'SATIPO' => 'Satipo',
        'CORONEL PORTILLO' => 'Coronel Portillo', 'CALLERIA' => 'Callería', 'MARISCAL NIETO' => 'Mariscal Nieto', 'SANCHEZ CARRION' => 'Sánchez Carrión',
        'HUAMANGA' => 'Huamanga', 'BAGUA' => 'Bagua', 'ANDAHUAYLAS' => 'Andahuaylas', 'AMARILIS' => 'Amarilis', 'PILLCO MARCA' => 'Pillco Marca',
    ];

    /**
     * Busca por palabras (nombre, marca, código, código de barras, categoría y descripción), con tolerancia a tildes, abreviaturas y
     * errores de tipeo, ordenando por relevancia. Se exigen todas las palabras; si así no sale nada, una menos.
     */
    public function buscar(Builder $consulta, string $texto): Builder
    {
        $palabras = BusquedaProductos::palabras($texto);
        $busqueda = app(BusquedaProductos::class);

        return $busqueda->aplicar($consulta, $palabras, $busqueda->minimo($consulta, $palabras, conDescripcion: true), conDescripcion: true);
    }

    /** Marcas con productos visibles en la tienda (en una categoría, si se indica), con cuántos productos cada una. */
    public function marcas(Empresa $empresa, ?string $categoriaId = null): Collection
    {
        return Producto::query()
            ->join('marcas', 'marcas.id', '=', 'productos.marca_id')
            ->where('productos.empresa_id', $empresa->id)
            ->where('productos.activo', true)
            ->where('productos.en_tienda', true)
            ->when($categoriaId, fn ($q) => $q->where('productos.categoria_id', $categoriaId))
            ->whereHas('presentaciones', fn ($q) => $q->where('activo', true))
            ->groupBy('marcas.nombre')
            ->orderBy('marcas.nombre')
            // con el módulo Variantes encendido, las variantes de un modelo cuentan como un producto
            ->selectRaw('marcas.nombre, '.$this->cuenta($empresa).' AS productos')
            ->get()
            ->map(fn ($m) => ['nombre' => $m->nombre, 'productos' => (int) $m->productos]);
    }

    /** El precio de lista más alto de la tienda, para el tope del filtro por precio. */
    public function precioMaximo(Empresa $empresa): float
    {
        return (float) DB::query()
            ->fromSub($this->productos($empresa)->reorder()->toBase(), 'p')
            ->max('precio');
    }

    /** Cuántos productos se ven en la tienda (las variantes de un modelo cuentan como uno). */
    public function total(Empresa $empresa): int
    {
        return (int) Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->where('en_tienda', true)
            ->whereHas('presentaciones', fn ($q) => $q->where('activo', true))
            ->count($empresa->variantes_activo ? DB::raw('DISTINCT '.self::SQL_TARJETA) : '*');
    }

    /** Cómo se cuentan los productos de un listado: por modelo si la empresa usa variantes; si no, uno por uno. */
    private function cuenta(Empresa $empresa): string
    {
        return $empresa->variantes_activo ? 'COUNT(DISTINCT '.self::SQL_TARJETA.')' : 'COUNT(*)';
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
            ->groupBy('categorias.id', 'categorias.nombre', 'categorias.orden', 'categorias.imagen_url')
            // primero las que el dueño ordenó; el resto por nombre
            ->orderByRaw('categorias.orden NULLS LAST')
            ->orderBy('categorias.nombre')
            // dos categorías con el mismo nombre: siempre en el mismo orden, para que su dirección no se alterne
            ->orderBy('categorias.id')
            // la foto es la propia de la categoría o, si no subió una, la de cualquiera de sus productos
            ->selectRaw('categorias.id, categorias.nombre, categorias.orden, categorias.imagen_url AS imagen_propia, '.$this->cuenta($empresa).' as productos, COALESCE(categorias.imagen_url, MAX(productos.imagen_url)) as imagen')
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
     * Productos de la sección destacada de la portada. Con origen "auto": los que el dueño marcó con la
     * estrella; si no marcó ninguno, los más vendidos de los últimos 90 días; y si la tienda es nueva y
     * aún no vende, los más recientes. El dueño también puede fijar uno de esos orígenes.
     *
     * @return array{origen: string, productos: Collection<int, Producto>}
     */
    public function destacados(Empresa $empresa, string $origen = 'auto'): array
    {
        if ($origen === 'nuevos') {
            return $this->nuevos($empresa);
        }

        if ($origen !== 'vendidos') {
            // en el orden que el dueño les dio; los que no ordenó, por nombre
            $marcados = $this->porModelo($empresa, $this->productos($empresa)->where('productos.destacado', true))
                ->orderByRaw('productos.destacado_orden NULLS LAST')->orderBy('productos.nombre')->limit(self::DESTACADOS)->get();

            if ($marcados->isNotEmpty() || $origen === 'elegidos') {
                return ['origen' => 'elegidos', 'productos' => $marcados];
            }
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
            $vendidos = $this->porModelo($empresa, $this->productos($empresa)->whereIn('productos.id', $ids))->get()
                ->sortBy(fn (Producto $p) => array_search($p->id, $ids, true))
                ->take(self::DESTACADOS)
                ->values();

            if ($vendidos->isNotEmpty()) {
                return ['origen' => 'vendidos', 'productos' => $vendidos];
            }
        }

        return $this->nuevos($empresa);
    }

    /** Los últimos productos que entraron a la tienda. */
    private function nuevos(Empresa $empresa): array
    {
        return [
            'origen' => 'nuevos',
            'productos' => $this->porModelo($empresa, $this->productos($empresa))->orderByDesc('productos.creado_en')->limit(self::DESTACADOS)->get(),
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

        // las otras tallas y colores del mismo modelo ya se eligen en la página: aquí van otros productos
        $sinSuModelo = fn (Builder $q, array $grupos) => $q->when($grupos, fn ($w) => $w->where(fn ($g) => $g
            ->whereNull('productos.grupo_id')->orWhereNotIn('productos.grupo_id', $grupos)));
        $suModelo = $this->tieneModelos($empresa) ? array_filter([$producto->grupo_id]) : [];

        $relacionados = $producto->categoria_id
            ? $this->porModelo($empresa, $sinSuModelo($this->productos($empresa), $suModelo)
                ->where('productos.categoria_id', $producto->categoria_id)
                ->whereKeyNot($producto->id))
                ->when($prefijo, fn ($q) => $q->orderByRaw('CASE WHEN productos.nombre ILIKE ? THEN 0 ELSE 1 END', [$prefijo]))
                ->orderByDesc('productos.destacado')
                ->orderBy('productos.nombre')
                ->limit($cuantos)
                ->get()
            : $this->productos($empresa)->whereRaw('false')->get();

        // en una categoría chica se completa con productos de nombre parecido ("Urea ..."), aunque sean de otra
        if ($relacionados->count() < $cuantos && $prefijo) {
            $parecidos = $this->porModelo($empresa, $sinSuModelo($this->productos($empresa), $suModelo ? [...$suModelo, ...$relacionados->pluck('grupo_id')->filter()->all()] : [])
                ->where('productos.nombre', 'ilike', $prefijo)
                ->whereKeyNot($producto->id)
                ->whereNotIn('productos.id', $relacionados->modelKeys()))
                ->orderBy('productos.nombre')
                ->limit($cuantos - $relacionados->count())
                ->get();
            $relacionados = $relacionados->concat($parecidos);
        }

        return $relacionados;
    }

    /**
     * Datos de un producto para su página. El precio solo sale si la tienda los muestra, y la disponibilidad
     * es sí o no: nunca cuántas unidades hay. Si es variante de un modelo trae el modelo completo: todas sus
     * variantes visibles y el selector de talla y color (para un listado, tarjetas()).
     */
    public function tarjeta(Producto $producto, array $config, ?Empresa $empresa = null): array
    {
        $modelo = null;
        if ($producto->grupo_id && $this->moduloActivo(collect([$producto]), $empresa)[$producto->empresa_id]) {
            $modelo = $this->modelo($producto, $config, $this->modelos(collect([$producto]))[$producto->grupo_id] ?? null);
        }
        $datos = $this->datos($producto, $config);

        // lo que se pide o se añade de una variante dice su talla y color (igual que en la lista del modelo)
        if ($actual = collect($modelo['variantes'] ?? [])->firstWhere('actual', true)) {
            $datos = [...$datos, 'pedido' => $actual['pedido'], 'para_pedido' => $actual['para_pedido']];
        }

        return [...$datos, 'modelo' => $modelo];
    }

    /**
     * Las tarjetas de un listado. De cada modelo solo se trae un resumen (nombre, precio más bajo, cuántas
     * variantes, qué varía, si hay stock y una foto) con dos consultas para toda la página, sin leer sus
     * variantes una por una. Un listado sin variantes no consulta nada más.
     *
     * @param  iterable<Producto>  $productos
     * @return Collection<int, array>
     */
    public function tarjetas(iterable $productos, array $config, ?Empresa $empresa = null): Collection
    {
        $productos = collect($productos);
        $activo = $this->moduloActivo($productos, $empresa);
        $resumenes = $this->resumenes($productos->filter(fn (Producto $p) => $p->grupo_id && $activo[$p->empresa_id]));

        return $productos->map(function (Producto $producto) use ($config, $resumenes) {
            $resumen = $producto->grupo_id ? ($resumenes[$producto->grupo_id] ?? null) : null;
            if (! $resumen) {
                return [...$this->datos($producto, $config), 'modelo' => null];
            }

            $precios = $config['mostrar_precios'] && $resumen->precio_desde !== null;
            $nombres = array_column($resumen->atributos, 'nombre');

            return [
                ...$this->datos($producto, $config, self::etiqueta($nombres, self::valoresDe($producto, $nombres)) ?: null),
                'modelo' => [
                    'nombre' => Tienda::nombreVisible($resumen->nombre, $config),
                    'cuantas' => (int) $resumen->cuantas,
                    'precio_desde' => $precios ? (float) $resumen->precio_desde : null,
                    'varios_precios' => $precios && (int) $resumen->precios > 1,
                    'hay_stock' => (bool) $resumen->hay_stock,
                    'disponible' => $config['mostrar_stock'] ? (bool) $resumen->hay_stock : null,
                    'variacion' => self::variacion(array_map(fn ($n) => [$n, $resumen->valores[$n] ?? 0], $nombres)),
                    'imagen' => $resumen->imagen_url ?: $resumen->imagen,
                ],
            ];
        })->toBase();
    }

    /**
     * ¿Tiene cada empresa de estos productos el módulo Variantes encendido? [empresa_id => bool].
     * Solo se consulta si hay alguna variante y no se sabe ya de qué empresa es el listado.
     */
    private function moduloActivo(Collection $productos, ?Empresa $empresa): array
    {
        $empresas = $productos->pluck('empresa_id')->unique();
        $activo = $empresas->mapWithKeys(fn ($id) => [$id => false])->all();
        if ($empresa) {
            $activo[$empresa->id] = (bool) $empresa->variantes_activo;
        }

        $porSaber = $productos->filter(fn (Producto $p) => $p->grupo_id && $p->empresa_id !== $empresa?->id)->pluck('empresa_id')->unique();
        if ($porSaber->isNotEmpty()) {
            foreach (Empresa::query()->whereIn('id', $porSaber)->pluck('variantes_activo', 'id') as $id => $encendido) {
                $activo[$id] = (bool) $encendido;
            }
        }

        return $activo;
    }

    /**
     * El resumen de cada modelo de un listado, sacado con agregados (no se leen sus variantes): una consulta
     * con el precio más bajo, si hay varios precios, cuántas variantes se ven, si alguna tiene stock y una foto,
     * y otra con cuántos valores distintos hay de cada característica. Siempre dentro de la empresa del producto.
     *
     * @return array<string, object> grupo_id => resumen
     */
    private function resumenes(Collection $variantes): array
    {
        $resumenes = [];

        foreach ($variantes->groupBy('empresa_id') as $empresaId => $deLaEmpresa) {
            $ids = $deLaEmpresa->pluck('grupo_id')->unique()->values()->all();

            $modelos = DB::query()
                ->fromSub($this->visibles((string) $empresaId)->whereIn('productos.grupo_id', $ids)->toBase(), 'v')
                ->join('producto_grupos as g', 'g.id', '=', 'v.grupo_id')
                ->where('g.empresa_id', $empresaId)
                ->groupBy('g.id', 'g.nombre', 'g.atributos', 'g.imagen_url')
                ->selectRaw('g.id, g.nombre, g.atributos, g.imagen_url, COUNT(*) AS cuantas, MIN(v.precio) AS precio_desde, COUNT(DISTINCT v.precio) AS precios, '
                    .'BOOL_OR(NOT v.controla_stock OR COALESCE(v.stock_total, 0) > 0) AS hay_stock, '
                    .'(ARRAY_AGG(v.imagen_url ORDER BY v.variante_orden NULLS LAST, v.nombre, v.id) FILTER (WHERE v.imagen_url IS NOT NULL))[1] AS imagen')
                ->get();

            $valores = $this->visibles((string) $empresaId)->whereIn('productos.grupo_id', $ids)->toBase()
                ->crossJoin(DB::raw("LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(productos.atributos) = 'object' THEN productos.atributos ELSE '{}'::jsonb END) AS a(clave, valor)"))
                ->groupBy('productos.grupo_id', 'a.clave')
                ->select('productos.grupo_id', 'a.clave')
                ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(a.valor), '')) AS valores")
                ->get()
                ->groupBy('grupo_id');

            foreach ($modelos as $modelo) {
                $modelo->atributos = array_values(json_decode((string) $modelo->atributos, true) ?: []);
                $modelo->valores = ($valores->get($modelo->id) ?? collect())->pluck('valores', 'clave')->map(fn ($n) => (int) $n)->all();
                $resumenes[$modelo->id] = $modelo;
            }
        }

        return $resumenes;
    }

    /** Los valores de una variante en el orden de las características del modelo: ["M", "Rojo"]. */
    private static function valoresDe(Producto $variante, array $nombres): array
    {
        return array_map(fn ($nombre) => trim((string) (($variante->atributos ?? [])[$nombre] ?? '')), $nombres);
    }

    /** "Talla M, Color Rojo": la variante con todas las letras, para el pedido y el mensaje de WhatsApp. */
    private static function etiqueta(array $nombres, array $valores): string
    {
        return implode(', ', array_filter(array_map(fn ($nombre, $valor) => $valor !== '' ? "{$nombre} {$valor}" : null, $nombres, $valores)));
    }

    /**
     * "3 tallas · 2 colores": lo que el cliente va a poder elegir (una característica con un solo valor no se nombra).
     *
     * @param  list<array{0: string, 1: int}>  $porAtributo  [nombre, cuántos valores distintos]
     */
    private static function variacion(array $porAtributo): ?string
    {
        return implode(' · ', array_filter(array_map(fn ($a) => $a[1] > 1 ? $a[1].' '.self::plural($a[0]) : null, $porAtributo))) ?: null;
    }

    /** Los datos de un producto por sí solo (también los de cada variante de un modelo). */
    private function datos(Producto $producto, array $config, ?string $variante = null): array
    {
        $principal = $producto->presentaciones->first();
        // en una variante el pedido dice además su talla y color con todas las letras
        $deVariante = $variante !== null ? "\n({$variante})" : '';

        return [
            'id' => $producto->id,
            'nombre' => Tienda::nombreVisible($producto->nombre, $config),
            'codigo' => $producto->codigo_interno,
            'marca' => $producto->marca?->nombre,
            'categoria' => $producto->categoria?->nombre,
            'categoria_id' => $producto->categoria_id,
            'imagen' => $producto->imagen_url,
            // foto chica para las tarjetas (null mientras no exista: se usa la grande)
            'miniatura' => $producto->imagen_miniatura,
            'descripcion' => $producto->descripcion,
            'destacado' => (bool) $producto->destacado,
            'url' => $this->url($producto),
            'precio' => $precio = $config['mostrar_precios'] && $principal ? (float) $principal->precio_venta : null,
            'presentacion' => $presentacion = $principal && $producto->presentaciones->count() > 1 ? $principal->nombre : null,
            // pedir solo este producto por WhatsApp (null si la tienda no tiene WhatsApp)
            'pedido' => Tienda::enlaceWhatsapp($config['whatsapp'], "Hola, quiero pedir este producto:\n{$producto->nombre}{$deVariante}"),
            // lo que guarda el pedido del cliente al tocar "Añadir"
            'para_pedido' => [
                'id' => (string) $producto->id,
                'nombre' => $producto->nombre,
                'unidad' => $presentacion,
                'precio' => $precio,
                'imagen' => $producto->imagen_url,
                'url' => $this->url($producto),
                ...($variante !== null ? ['variante' => $variante] : []),
            ],
            // hay para vender (sin importar si la tienda lo muestra): para los datos del buscador
            'hay_stock' => ! $producto->controla_stock || (float) $producto->stock_total > 0,
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

    /**
     * El modelo completo al que pertenece una variante, para su página: lo mismo que el resumen de un listado
     * más cada variante visible (precio, disponibilidad, lo que se añade al pedido) y el selector de talla y color.
     * Null si no hay modelo que mostrar.
     */
    private function modelo(Producto $producto, array $config, ?array $modelo): ?array
    {
        $grupo = $modelo['grupo'] ?? null;
        $hermanas = $modelo['variantes'] ?? collect();
        if (! $grupo || $hermanas->isEmpty()) {
            return null;
        }

        $definicion = array_values($grupo->atributos ?? []);
        $nombres = array_column($definicion, 'nombre');
        // una variante sin foto propia se muestra con la del modelo o, si tampoco tiene, con la de otra variante
        $fotoModelo = $grupo->imagen_url ?: $hermanas->first(fn (Producto $v) => filled($v->imagen_url))?->imagen_url;

        $variantes = $hermanas->map(function (Producto $v) use ($config, $nombres, $producto, $fotoModelo) {
            $valores = self::valoresDe($v, $nombres);
            $datos = $this->datos($v, $config, self::etiqueta($nombres, $valores) ?: null);

            return [
                'id' => (string) $v->id,
                'nombre' => $datos['nombre'],
                'codigo' => $datos['codigo'],
                'valores' => $valores,
                'url' => $datos['url'],
                'precio' => $datos['precio'],
                'mayorista' => $datos['presentaciones'][0]['mayorista'] ?? null,
                'hay_stock' => $datos['hay_stock'],
                'disponible' => $datos['disponible'],
                'imagen' => $datos['imagen'] ?: $fotoModelo,
                'pedido' => $datos['pedido'],
                'para_pedido' => $datos['para_pedido'],
                // con más de una presentación la página no se puede cambiar al vuelo: se abre la de esa variante
                'presentaciones' => count($datos['presentaciones']),
                'actual' => $v->is($producto),
            ];
        })->values();

        // solo los valores que alguna variante visible tiene, en el orden en que el dueño los escribió
        $atributos = [];
        foreach ($definicion as $i => $atributo) {
            $usados = $variantes->map(fn ($v) => $v['valores'][$i])->filter(fn ($v) => $v !== '')->unique()->values()->all();
            $orden = array_map(fn ($v) => trim((string) $v), $atributo['valores'] ?? []);
            $puesto = fn (string $valor) => ($p = array_search($valor, $orden, true)) === false ? PHP_INT_MAX : $p;
            usort($usados, fn ($a, $b) => $puesto($a) <=> $puesto($b));
            $atributos[] = ['nombre' => (string) $atributo['nombre'], 'valores' => $usados];
        }

        $precios = $variantes->pluck('precio')->filter(fn ($p) => $p !== null);
        $actual = $variantes->firstWhere('actual', true) ?? $variantes->first();

        // el selector de la página: cada valor lleva a la variante disponible que lo tiene y más se parece a la elegida
        $selector = [];
        foreach ($atributos as $i => $atributo) {
            $selector[] = [
                'nombre' => $atributo['nombre'],
                'elegido' => $actual['valores'][$i],
                'opciones' => array_map(function (string $valor) use ($variantes, $actual, $i) {
                    $destino = self::varianteCon($variantes->all(), $actual['valores'], $i, $valor);

                    return [
                        'valor' => $valor,
                        'url' => $destino['url'],
                        'elegida' => $valor === $actual['valores'][$i],
                        // tachada: no queda ninguna variante disponible con ese valor (se puede mirar y consultar, no añadir)
                        'agotada' => $destino['disponible'] === false,
                        // con lo demás que hay elegido esa combinación no existe o está agotada: al tocarla cambia también lo otro
                        'salta' => self::coincidencias($destino['valores'], $actual['valores'], $i) < count($actual['valores']) - 1,
                    ];
                }, $atributo['valores']),
            ];
        }

        return [
            'nombre' => Tienda::nombreVisible($grupo->nombre, $config),
            'cuantas' => $variantes->count(),
            'precio_desde' => $precios->isNotEmpty() ? (float) $precios->min() : null,
            'precio_hasta' => $precios->isNotEmpty() ? (float) $precios->max() : null,
            'varios_precios' => $precios->unique()->count() > 1,
            'hay_stock' => $variantes->contains('hay_stock', true),
            'disponible' => $config['mostrar_stock'] ? $variantes->contains('hay_stock', true) : null,
            'variacion' => self::variacion(array_map(fn ($a) => [$a['nombre'], count($a['valores'])], $atributos)),
            'atributos' => $atributos,
            'selector' => $selector,
            'variantes' => $variantes->all(),
            // si cada variante tiene una sola presentación, la página cambia de una a otra sin recargar
            'al_vuelo' => $variantes->every(fn ($v) => $v['presentaciones'] === 1),
            // la dirección que se da a los buscadores para todo el modelo: la primera variante visible, haya o no
            // stock (la misma que entra al sitemap), para que no cambie cada vez que una talla se agota
            'canonica' => $variantes->first()['url'],
            'imagen' => $fotoModelo,
        ];
    }

    /**
     * Los modelos de los productos de un listado, con sus variantes visibles en el orden de sus combinaciones:
     * grupo_id => ['grupo' => ProductoGrupo, 'variantes' => Collection<Producto>]. Cada modelo se busca dentro
     * de la empresa del producto: nunca entran variantes de otra.
     */
    private function modelos(Collection $productos): array
    {
        $modelos = [];

        foreach ($productos->filter(fn (Producto $p) => (bool) $p->grupo_id)->groupBy('empresa_id') as $empresaId => $deLaEmpresa) {
            $ids = $deLaEmpresa->pluck('grupo_id')->unique()->values()->all();
            $grupos = ProductoGrupo::withTrashed()->where('empresa_id', $empresaId)->whereIn('id', $ids)->get()->keyBy('id');
            $variantes = $this->visibles((string) $empresaId)
                ->whereIn('productos.grupo_id', $ids)
                ->orderByRaw('productos.variante_orden NULLS LAST')->orderBy('productos.nombre')->orderBy('productos.id')
                ->get()
                ->groupBy('grupo_id');

            foreach ($ids as $id) {
                $modelos[$id] = ['grupo' => $grupos->get($id), 'variantes' => $variantes->get($id) ?? collect()];
            }
        }

        return $modelos;
    }

    /**
     * La variante a la que lleva elegir $valor en el atributo $indice. De las que tienen ese valor:
     * primero las disponibles y, entre ellas, la que coincide en más de lo ya elegido (si la combinación exacta
     * está agotada pero hay otra disponible con ese valor, se va a la disponible); si ninguna está disponible,
     * la agotada que más se parece. A igualdad, la primera en el orden del modelo. "Disponible" es lo que la
     * tienda muestra: si no muestra disponibilidad, todas cuentan como disponibles y solo manda el parecido.
     * (tienda.js hace la misma cuenta al cambiar de variante sin recargar.)
     */
    private static function varianteCon(array $variantes, array $elegidos, int $indice, string $valor): array
    {
        $mejor = null;
        $puntos = -1;
        foreach ($variantes as $variante) {
            if ($variante['valores'][$indice] !== $valor) {
                continue;
            }
            $suyos = ($variante['disponible'] !== false ? 100 : 0) + self::coincidencias($variante['valores'], $elegidos, $indice);
            if ($suyos > $puntos) {
                [$mejor, $puntos] = [$variante, $suyos];
            }
        }

        return $mejor;
    }

    /** En cuántos atributos (sin contar $salvo) dos combinaciones tienen el mismo valor. */
    private static function coincidencias(array $valores, array $elegidos, int $salvo): int
    {
        $iguales = 0;
        foreach ($valores as $i => $valor) {
            $iguales += $i !== $salvo && $valor === ($elegidos[$i] ?? null) ? 1 : 0;
        }

        return $iguales;
    }

    /** "Talla" -> "tallas", "Color" -> "colores", "Capacidad" -> "capacidades". */
    private static function plural(string $nombre): string
    {
        $nombre = mb_strtolower(trim($nombre));

        return match (true) {
            $nombre === '' => 'opciones',
            (bool) preg_match('/[aeiouáéó]$/u', $nombre) => $nombre.'s',
            str_ends_with($nombre, 'z') => mb_substr($nombre, 0, -1).'ces',
            str_ends_with($nombre, 's') => $nombre,
            default => $nombre.'es',
        };
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
