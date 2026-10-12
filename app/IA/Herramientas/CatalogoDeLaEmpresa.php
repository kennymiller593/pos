<?php

namespace App\IA\Herramientas;

use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Database\Eloquent\Builder;

/** Lo que comparten las herramientas del catálogo: consultas acotadas a la empresa y formato de un producto. */
abstract class CatalogoDeLaEmpresa
{
    /** Largo máximo de la descripción que se le pasa al modelo en una búsqueda. */
    protected const DESCRIPCION_CORTA = 500;

    public function __construct(
        protected readonly Empresa $empresa,
        protected readonly ?Sucursal $sucursal = null,
    ) {}

    protected function consulta(): Builder
    {
        $sucursalId = $this->sucursal?->id;

        return Producto::query()
            ->where('empresa_id', $this->empresa->id)
            ->where('activo', true)
            ->with([
                'categoria:id,nombre',
                'marca:id,nombre',
                'presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre'),
            ])
            ->withSum(['stock as stock_total' => fn ($q) => $sucursalId ? $q->where('sucursal_id', $sucursalId) : $q], 'cantidad');
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
            $precio = number_format((float) $pres->precio_venta, 2);
            $extra = $pres->precio_mayorista !== null && $pres->cantidad_mayorista
                ? ' (mayorista S/ '.number_format((float) $pres->precio_mayorista, 2)." desde {$pres->cantidad_mayorista})"
                : '';
            $lineas[] = "Presentación: {$pres->nombre} · S/ {$precio}{$extra}";
        }
        $stock = (float) ($p->stock_total ?? 0);
        $lineas[] = $p->controla_stock
            ? 'Stock: '.($stock > 0 ? rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.').' '.($p->unidad_base_codigo ?: 'unid.') : 'sin stock')
            : 'Stock: no se controla (siempre disponible)';
        if (filled($p->descripcion)) {
            $texto = trim(preg_replace('/\s+/', ' ', strip_tags((string) $p->descripcion)));
            $lineas[] = 'Descripción: '.($completo || mb_strlen($texto) <= self::DESCRIPCION_CORTA ? $texto : mb_substr($texto, 0, self::DESCRIPCION_CORTA).'…');
        }

        return implode("\n", $lineas);
    }
}
