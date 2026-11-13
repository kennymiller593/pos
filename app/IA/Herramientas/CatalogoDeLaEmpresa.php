<?php

namespace App\IA\Herramientas;

use App\Models\Empresa;
use App\Models\Producto;
use App\Models\ProductoGrupo;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que comparten las herramientas del catálogo: consultas acotadas a la empresa y formato de un producto.
 * En modo público (tienda en línea) solo se ven los productos publicados y lo que la tienda decide mostrar.
 */
abstract class CatalogoDeLaEmpresa
{
    /** Largo máximo de la descripción que se le pasa al modelo en una búsqueda. */
    protected const DESCRIPCION_CORTA = 260;

    /** Cuántas variantes de un modelo se listan como máximo (un modelo puede tener hasta 200 combinaciones). */
    protected const MAX_VARIANTES = 40;

    public function __construct(
        protected readonly Empresa $empresa,
        protected readonly ?Sucursal $sucursal = null,
        protected readonly bool $publico = false,
        protected readonly bool $conPrecios = true,
        protected readonly bool $conStock = true,
    ) {}

    protected function consulta(): Builder
    {
        $sucursalId = $this->sucursal?->id;

        return Producto::query()
            ->where('empresa_id', $this->empresa->id)
            ->where('activo', true)
            ->when($this->publico, fn ($q) => $q->where('en_tienda', true))
            ->with([
                'categoria:id,nombre',
                'marca:id,nombre',
                'presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre'),
            ])
            // en la tienda pública la disponibilidad es la de todo el negocio, no la de una sucursal
            ->withSum(['stock as stock_total' => fn ($q) => $sucursalId && ! $this->publico ? $q->where('sucursal_id', $sucursalId) : $q], 'cantidad');
    }

    /** Texto compacto de un producto, apto para que el modelo lo lea y lo cite. */
    protected function describir(Producto $p, bool $completo = false): string
    {
        $lineas = ["Producto: {$p->nombre} (código {$p->codigo_interno})"];
        if ($p->categoria) {
            $lineas[] = "Categoría: {$p->categoria->nombre}";
        }
        if ($p->marca) {
            $lineas[] = "Marca: {$p->marca->nombre}";
        }
        foreach ($p->presentaciones as $pres) {
            if (! $this->conPrecios) {
                $lineas[] = "Presentación: {$pres->nombre}";

                continue;
            }
            $precio = number_format((float) $pres->precio_venta, 2);
            $extra = ! $this->publico && $pres->precio_mayorista !== null && $pres->cantidad_mayorista
                ? ' (mayorista S/ '.number_format((float) $pres->precio_mayorista, 2)." desde {$pres->cantidad_mayorista})"
                : '';
            $lineas[] = "Presentación: {$pres->nombre} · S/ {$precio}{$extra}";
        }
        if ($this->conStock) {
            $stock = (float) ($p->stock_total ?? 0);
            $hay = ! $p->controla_stock || $stock > 0;
            $lineas[] = match (true) {
                $this->publico => 'Disponibilidad: '.($hay ? 'disponible' : 'agotado'),
                ! $p->controla_stock => 'Stock: no se controla (siempre disponible)',
                default => 'Stock: '.($stock > 0 ? rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.').' '.($p->unidad_base_codigo ?: 'unid.') : 'sin stock'),
            };
        }
        if (filled($p->descripcion)) {
            $texto = trim(preg_replace('/\s+/', ' ', strip_tags((string) $p->descripcion)));
            $lineas[] = 'Descripción: '.($completo || mb_strlen($texto) <= self::DESCRIPCION_CORTA ? $texto : mb_substr($texto, 0, self::DESCRIPCION_CORTA).'…');
        }
        // variante de un modelo (tallas, colores...): qué otras hay, para responder "¿lo tienen en talla M?" sin otra búsqueda
        // (con el módulo Variantes apagado, cada variante es un producto suelto: igual que en el POS y en la tienda)
        if ($p->grupo_id && $this->empresa->variantes_activo) {
            array_push($lineas, ...$this->variantes($p));
        }

        return implode("\n", $lineas);
    }

    /**
     * Las líneas que cuentan de qué modelo es variante un producto y qué combinaciones existen:
     * "Variantes: Talla S, M, L · Color Rojo, Azul" y, si se informa el stock, cuáles hay y cuáles están agotadas
     * (cada una con su código, para poder citarla). Solo entran variantes de la misma empresa que se pueden ver.
     *
     * @return list<string>
     */
    protected function variantes(Producto $p): array
    {
        $grupo = ProductoGrupo::query()->where('empresa_id', $this->empresa->id)->find($p->grupo_id);
        $hermanas = $grupo
            ? $this->consulta()->where('grupo_id', $grupo->id)->orderByRaw('variante_orden NULLS LAST')->orderBy('nombre')->get()
                ->filter(fn (Producto $v) => $v->presentaciones->isNotEmpty())->values()
            : collect();
        if ($hermanas->count() < 2) {
            return [];
        }

        $nombres = array_column($grupo->atributos ?? [], 'nombre');
        $valores = fn (Producto $v) => array_values(array_filter(array_map(fn ($n) => trim((string) (($v->atributos ?? [])[$n] ?? '')), $nombres), fn ($x) => $x !== ''));
        $combinacion = fn (Producto $v) => implode(' ', $valores($v)) ?: $v->nombre;

        // por atributo, los valores que de verdad existen
        $porAtributo = [];
        foreach ($nombres as $nombre) {
            $existen = $hermanas->map(fn (Producto $v) => trim((string) (($v->atributos ?? [])[$nombre] ?? '')))->filter(fn ($x) => $x !== '')->unique()->implode(', ');
            if ($existen !== '') {
                $porAtributo[] = "{$nombre} {$existen}";
            }
        }

        $lineas = [
            "Modelo: {$grupo->nombre} (este producto es una de sus {$hermanas->count()} variantes; el cliente elige la suya en la página del producto)",
            'Variantes: '.implode(' · ', $porAtributo),
        ];

        $precios = $hermanas->map(fn (Producto $v) => (float) $v->presentaciones->first()->precio_venta);
        $detalle = function (Producto $v) use ($combinacion, $precios) {
            $precio = $this->conPrecios && $precios->unique()->count() > 1 ? ', S/ '.number_format((float) $v->presentaciones->first()->precio_venta, 2) : '';
            $stock = ! $this->publico && $this->conStock && $v->controla_stock
                ? ', stock '.rtrim(rtrim(number_format((float) ($v->stock_total ?? 0), 2, '.', ''), '0'), '.')
                : '';

            return "{$combinacion($v)} (código {$v->codigo_interno}{$precio}{$stock})";
        };
        $listar = function ($variantes) use ($detalle) {
            $texto = $variantes->take(self::MAX_VARIANTES)->map($detalle)->implode('; ');

            return $texto.($variantes->count() > self::MAX_VARIANTES ? '; y '.($variantes->count() - self::MAX_VARIANTES).' más' : '');
        };

        if (! $this->conStock) {
            $lineas[] = 'Combinaciones: '.$listar($hermanas);

            return $lineas;
        }

        [$hay, $agotadas] = $hermanas->partition(fn (Producto $v) => ! $v->controla_stock || (float) ($v->stock_total ?? 0) > 0);
        $lineas[] = 'Variantes con stock: '.($hay->isNotEmpty() ? $listar($hay->values()) : 'ninguna por ahora');
        if ($agotadas->isNotEmpty()) {
            $lineas[] = 'Variantes agotadas: '.$listar($agotadas->values());
        }

        return $lineas;
    }
}
