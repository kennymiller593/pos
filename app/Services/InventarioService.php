<?php

namespace App\Services;

use App\Models\CapaCosto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Stock;

/**
 * Operaciones de inventario con costeo FIFO por capas.
 * Todos los metodos asumen que se llaman dentro de una transaccion
 * cuando forman parte de una operacion compuesta.
 */
class InventarioService
{
    public function stockDisponible(string $productoId, string $sucursalId): float
    {
        return (float) Stock::query()
            ->where('producto_id', $productoId)
            ->where('sucursal_id', $sucursalId)
            ->value('cantidad');
    }

    public function incrementarStock(string $empresaId, string $productoId, string $sucursalId, float $cantidad): void
    {
        Stock::firstOrCreate(
            ['producto_id' => $productoId, 'sucursal_id' => $sucursalId],
            ['empresa_id' => $empresaId, 'cantidad' => 0]
        )->increment('cantidad', $cantidad);
    }

    public function descontarStock(string $productoId, string $sucursalId, float $cantidad): void
    {
        Stock::query()
            ->where('producto_id', $productoId)
            ->where('sucursal_id', $sucursalId)
            ->decrement('cantidad', $cantidad);
    }

    /** Busca o crea el lote del producto en la sucursal. */
    public function obtenerLote(Producto $producto, string $sucursalId, string $numeroLote, ?string $fechaVencimiento = null): Lote
    {
        return Lote::firstOrCreate(
            [
                'empresa_id' => $producto->empresa_id,
                'producto_id' => $producto->id,
                'sucursal_id' => $sucursalId,
                'numero_lote' => trim($numeroLote),
            ],
            ['fecha_vencimiento' => $fechaVencimiento],
        );
    }

    /** Crea una capa de costo nueva (compra o ajuste de entrada). */
    public function ingresarCapa(
        Producto $producto,
        string $sucursalId,
        float $cantidad,
        float $costoUnitario,
        ?string $compraDetalleId = null,
        ?string $loteId = null,
    ): CapaCosto {
        return CapaCosto::create([
            'empresa_id' => $producto->empresa_id,
            'producto_id' => $producto->id,
            'sucursal_id' => $sucursalId,
            'compra_detalle_id' => $compraDetalleId,
            'lote_id' => $loteId,
            'cantidad_inicial' => $cantidad,
            'cantidad_restante' => $cantidad,
            'costo_unitario' => round($costoUnitario, 6),
            'fecha_ingreso' => now(),
        ]);
    }

    /**
     * Consume capas de costo (bloqueandolas) y devuelve el detalle.
     * Orden FEFO: primero lo que vence antes (capas con lote), luego FIFO por ingreso.
     *
     * @return array{consumos: list<array{capa_id: string, cantidad: float, costo_unitario: float, lote_id: string|null}>, costo_total: float}
     */
    public function consumirFifo(string $productoId, string $sucursalId, float $cantidad): array
    {
        $porConsumir = $cantidad;
        $costoTotal = 0.0;
        $consumos = [];

        $capas = CapaCosto::query()
            ->where('producto_id', $productoId)
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad_restante', '>', 0)
            ->orderByRaw('(select l.fecha_vencimiento from lotes l where l.id = capas_costo.lote_id) asc nulls last')
            ->orderBy('fecha_ingreso')
            ->lockForUpdate()
            ->get();

        foreach ($capas as $capa) {
            if ($porConsumir <= 0) {
                break;
            }

            $tomar = min($porConsumir, (float) $capa->cantidad_restante);
            $capa->decrement('cantidad_restante', $tomar);
            $costoTotal += $tomar * (float) $capa->costo_unitario;
            $consumos[] = [
                'capa_id' => $capa->id,
                'cantidad' => $tomar,
                'costo_unitario' => (float) $capa->costo_unitario,
                'lote_id' => $capa->lote_id,
            ];
            $porConsumir = round($porConsumir - $tomar, 3);
        }

        return ['consumos' => $consumos, 'costo_total' => $costoTotal];
    }

    /** Devuelve consumos a sus capas originales (anulacion de venta). */
    public function revertirConsumos(iterable $consumos): void
    {
        foreach ($consumos as $consumo) {
            CapaCosto::whereKey($consumo->capa_id)->increment('cantidad_restante', $consumo->cantidad);
        }
    }

    /** Registra un movimiento en el kardex. */
    public function registrarMovimiento(
        string $empresaId,
        string $sucursalId,
        string $productoId,
        string $tipo,
        float $cantidad,
        ?float $costoUnitario = null,
        ?string $referenciaId = null,
        ?string $usuarioId = null,
        ?string $loteId = null,
    ): void {
        MovimientoInventario::create([
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'producto_id' => $productoId,
            'lote_id' => $loteId,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario !== null ? round($costoUnitario, 6) : null,
            'referencia_id' => $referenciaId,
            'usuario_id' => $usuarioId,
        ]);
    }
}
