<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Auditoria;
use App\Models\CapaCosto;
use App\Models\Mezcla;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Receta;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Mezclas y preparados: los insumos salen del stock a su costo FIFO real y el producto obtenido entra
 * con el costo calculado (insumos + otros costos) / cantidad obtenida. No es venta ni compra.
 */
class MezclaService
{
    public function __construct(private readonly InventarioService $inventario) {}

    /**
     * $datos: producto_id, presentacion_id, cantidad, numero_lote?, fecha_vencimiento?, otros_costos?, observacion?,
     *         guardar_formula?, insumos[{producto_id, presentacion_id, cantidad}]
     *
     * @throws ErrorDeNegocio
     */
    public function registrar(Usuario $usuario, string $sucursalId, array $datos): Mezcla
    {
        $empresaId = $usuario->empresa_id;

        $producto = Producto::query()->where('empresa_id', $empresaId)->where('activo', true)->find($datos['producto_id']);
        if (! $producto) {
            throw new ErrorDeNegocio('El producto a obtener no existe o está desactivado.');
        }
        if (! $producto->controla_stock) {
            throw new ErrorDeNegocio("\"{$producto->nombre}\" no controla stock: no se puede obtener de una mezcla.");
        }

        $presentacion = $this->presentacionDe($producto, $datos['presentacion_id'] ?? null);
        $cantidad = round((float) $datos['cantidad'], 3);
        $cantidadBase = round($cantidad * (float) $presentacion->factor_conversion, 3);
        if (! $producto->permite_fraccion && fmod($cantidadBase, 1) != 0) {
            throw new ErrorDeNegocio("\"{$producto->nombre}\" no permite cantidades fraccionadas.");
        }
        if ($producto->controla_lote && blank($datos['numero_lote'] ?? null)) {
            throw new ErrorDeNegocio("\"{$producto->nombre}\" controla lotes: indica el número de lote de la tanda.");
        }

        // insumos: se consolidan por presentación y se validan antes de tocar nada
        $lineas = collect($datos['insumos'])
            ->groupBy(fn ($i) => $i['producto_id'].'|'.($i['presentacion_id'] ?? ''))
            ->map(fn ($grupo) => ['producto_id' => $grupo->first()['producto_id'], 'presentacion_id' => $grupo->first()['presentacion_id'] ?? null, 'cantidad' => round($grupo->sum('cantidad'), 3)])
            ->values();

        $insumos = Producto::query()->where('empresa_id', $empresaId)->whereIn('id', $lineas->pluck('producto_id'))->get()->keyBy('id');
        $porProducto = [];
        foreach ($lineas as $i => $linea) {
            $insumo = $insumos->get($linea['producto_id']);
            if (! $insumo) {
                throw new ErrorDeNegocio('Uno de los insumos elegidos ya no existe.');
            }
            if ($insumo->id === $producto->id) {
                throw new ErrorDeNegocio("\"{$producto->nombre}\" no puede ser insumo de sí mismo.");
            }
            if (! $insumo->controla_stock) {
                throw new ErrorDeNegocio("\"{$insumo->nombre}\" no controla stock: no puede ser insumo.");
            }
            $pres = $this->presentacionDe($insumo, $linea['presentacion_id']);
            $base = round($linea['cantidad'] * (float) $pres->factor_conversion, 3);
            if (! $insumo->permite_fraccion && fmod($base, 1) != 0) {
                throw new ErrorDeNegocio("\"{$insumo->nombre}\" no permite cantidades fraccionadas.");
            }
            $lineas[$i] = [...$linea, 'producto' => $insumo, 'presentacion' => $pres, 'cantidad_base' => $base];
            $porProducto[$insumo->id] = ($porProducto[$insumo->id] ?? 0) + $base;
        }
        foreach ($porProducto as $productoId => $base) {
            $disponible = $this->inventario->stockDisponible($productoId, $sucursalId);
            if ($base > $disponible + 0.0005) {
                $nombre = $insumos->get($productoId)->nombre;
                throw new ErrorDeNegocio("Stock insuficiente de \"{$nombre}\" (disponible: ".rtrim(rtrim(number_format($disponible, 3), '0'), '.').').');
            }
        }

        $otros = round((float) ($datos['otros_costos'] ?? 0), 2);

        $mezcla = DB::transaction(function () use ($usuario, $empresaId, $sucursalId, $datos, $producto, $presentacion, $cantidad, $cantidadBase, $lineas, $otros) {
            // numeración por empresa sin huecos ni repetidos
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["mezclas:{$empresaId}"]);
            $numero = (int) Mezcla::where('empresa_id', $empresaId)->max('numero') + 1;

            $mezcla = Mezcla::create([
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'usuario_id' => $usuario->id,
                'numero' => $numero,
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion->id,
                'cantidad' => $cantidad,
                'cantidad_base' => $cantidadBase,
                'costo_insumos' => 0,
                'otros_costos' => $otros,
                'costo_total' => 0,
                'costo_unitario' => 0,
                'observacion' => $datos['observacion'] ?? null,
            ]);

            // 1) los insumos salen del stock a su costo real
            $costoInsumos = 0.0;
            foreach ($lineas as $linea) {
                $consumo = $this->inventario->consumirFifo($linea['producto']->id, $sucursalId, $linea['cantidad_base']);
                $this->inventario->descontarStock($linea['producto']->id, $sucursalId, $linea['cantidad_base']);
                $costoUnitario = $linea['cantidad_base'] > 0 ? $consumo['costo_total'] / $linea['cantidad_base'] : 0;
                $costoInsumos += $consumo['costo_total'];

                $insumo = $mezcla->insumos()->create([
                    'producto_id' => $linea['producto']->id,
                    'presentacion_id' => $linea['presentacion']->id,
                    'cantidad' => $linea['cantidad'],
                    'cantidad_base' => $linea['cantidad_base'],
                    'costo_unitario' => round($costoUnitario, 6),
                    'total' => round($consumo['costo_total'], 2),
                ]);
                foreach ($consumo['consumos'] as $c) {
                    $insumo->consumos()->create(['capa_id' => $c['capa_id'], 'cantidad' => $c['cantidad'], 'costo_unitario' => $c['costo_unitario']]);
                }
                $this->inventario->registrarMovimiento($empresaId, $sucursalId, $linea['producto']->id, 'mezcla_salida', $linea['cantidad_base'], $costoUnitario, $mezcla->id, $usuario->id);
            }

            // 2) el producto obtenido entra con el costo calculado
            $costoTotal = round($costoInsumos + $otros, 2);
            $costoUnitario = $cantidadBase > 0 ? $costoTotal / $cantidadBase : 0;

            $loteId = null;
            if ($producto->controla_lote) {
                $loteId = $this->inventario->obtenerLote($producto, $sucursalId, $datos['numero_lote'], $datos['fecha_vencimiento'] ?? null)->id;
            }
            $capa = $this->inventario->ingresarCapa($producto, $sucursalId, $cantidadBase, $costoUnitario, loteId: $loteId);
            $this->inventario->incrementarStock($empresaId, $producto->id, $sucursalId, $cantidadBase);
            $this->inventario->registrarMovimiento($empresaId, $sucursalId, $producto->id, 'mezcla_entrada', $cantidadBase, $costoUnitario, $mezcla->id, $usuario->id, $loteId);

            $mezcla->update([
                'lote_id' => $loteId,
                'capa_id' => $capa->id,
                'costo_insumos' => round($costoInsumos, 2),
                'costo_total' => $costoTotal,
                'costo_unitario' => round($costoUnitario, 6),
            ]);

            // 3) fórmula guardada: la próxima vez solo se indica cuánto preparar
            if (! empty($datos['guardar_formula'])) {
                $this->guardarFormula($empresaId, $producto, $cantidadBase, $otros, $lineas);
            }

            return $mezcla;
        });

        Auditoria::registrar($usuario, 'mezcla.registrada', 'mezcla', $mezcla->id, [
            'numero' => $mezcla->codigo(),
            'producto' => $producto->nombre,
            'cantidad' => $cantidadBase,
            'costo_total' => (float) $mezcla->costo_total,
            'insumos' => $lineas->map(fn ($l) => ['producto' => $l['producto']->nombre, 'cantidad' => $l['cantidad_base']])->all(),
        ]);

        return $mezcla;
    }

    /**
     * Deshace la mezcla: lo obtenido sale del stock y los insumos vuelven a sus capas. Solo si nada de lo
     * obtenido se vendió o movió.
     *
     * @throws ErrorDeNegocio
     */
    public function anular(Mezcla $mezcla, Usuario $usuario, string $motivo): void
    {
        if ($mezcla->estado !== 'registrada') {
            throw new ErrorDeNegocio('Esta mezcla ya está anulada.');
        }

        DB::transaction(function () use ($mezcla, $usuario, $motivo) {
            $bloqueada = Mezcla::lockForUpdate()->find($mezcla->id);
            if (! $bloqueada || $bloqueada->estado !== 'registrada') {
                throw new ErrorDeNegocio('Esta mezcla ya está anulada.');
            }

            $mezcla->load(['producto' => fn ($q) => $q->withTrashed(), 'insumos.consumos', 'insumos.producto' => fn ($q) => $q->withTrashed()]);

            $capa = $mezcla->capa_id ? CapaCosto::lockForUpdate()->find($mezcla->capa_id) : null;
            if (! $capa || (float) $capa->cantidad_restante + 0.0005 < (float) $capa->cantidad_inicial) {
                throw new ErrorDeNegocio("No se puede anular: ya se vendió o movió parte de \"{$mezcla->producto->nombre}\" obtenido en esta mezcla. Registra un ajuste de stock en su lugar.");
            }

            // lo obtenido sale
            $capa->delete();
            $this->inventario->descontarStock($mezcla->producto_id, $mezcla->sucursal_id, (float) $mezcla->cantidad_base);
            $this->inventario->registrarMovimiento($mezcla->empresa_id, $mezcla->sucursal_id, $mezcla->producto_id, 'mezcla_anulada', (float) $mezcla->cantidad_base, (float) $mezcla->costo_unitario, $mezcla->id, $usuario->id, $mezcla->lote_id);

            // los insumos vuelven a las capas de donde salieron
            foreach ($mezcla->insumos as $insumo) {
                $this->inventario->revertirConsumos($insumo->consumos);
                $this->inventario->incrementarStock($mezcla->empresa_id, $insumo->producto_id, $mezcla->sucursal_id, (float) $insumo->cantidad_base);
                $this->inventario->registrarMovimiento($mezcla->empresa_id, $mezcla->sucursal_id, $insumo->producto_id, 'devolucion', (float) $insumo->cantidad_base, (float) $insumo->costo_unitario, $mezcla->id, $usuario->id);
            }

            $mezcla->update(['estado' => 'anulada', 'anulada_en' => now(), 'anulada_por' => $usuario->id, 'motivo_anulacion' => $motivo]);
        });

        Auditoria::registrar($usuario, 'mezcla.anulada', 'mezcla', $mezcla->id, [
            'numero' => $mezcla->codigo(),
            'producto' => $mezcla->producto->nombre,
            'motivo' => $motivo,
        ]);
    }

    /** La presentación indicada del producto, o la principal si no se indica. */
    private function presentacionDe(Producto $producto, ?string $presentacionId): ProductoPresentacion
    {
        $presentacion = $producto->presentaciones()->where('activo', true)
            ->when($presentacionId, fn ($q) => $q->whereKey($presentacionId), fn ($q) => $q->orderByDesc('es_default'))
            ->first();

        if (! $presentacion) {
            throw new ErrorDeNegocio("\"{$producto->nombre}\" no tiene esa presentación.");
        }

        return $presentacion;
    }

    private function guardarFormula(string $empresaId, Producto $producto, float $rindeBase, float $otros, iterable $lineas): void
    {
        $receta = Receta::updateOrCreate(['producto_id' => $producto->id], ['empresa_id' => $empresaId, 'rinde_base' => $rindeBase, 'otros_costos' => $otros]);
        $receta->insumos()->delete();
        foreach ($lineas as $linea) {
            $receta->insumos()->create([
                'producto_id' => $linea['producto']->id,
                'presentacion_id' => $linea['presentacion']->id,
                'cantidad_base' => $linea['cantidad_base'],
            ]);
        }
    }
}
