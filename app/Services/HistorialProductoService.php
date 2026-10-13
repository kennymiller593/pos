<?php

namespace App\Services;

use App\Models\CompraDetalle;
use App\Models\ComprobanteDetalle;
use App\Models\Producto;

/** Las últimas compras y ventas de un producto, para consultarlas rápido desde Productos o el POS. */
class HistorialProductoService
{
    public const LIMITE = 10;

    /** @return list<array<string, mixed>> */
    public function compras(Producto $producto, int $limite = self::LIMITE): array
    {
        return CompraDetalle::query()
            ->join('compras', 'compras.id', '=', 'compra_detalles.compra_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor_id')
            ->leftJoin('producto_presentaciones', 'producto_presentaciones.id', '=', 'compra_detalles.presentacion_id')
            ->leftJoin('capas_costo', 'capas_costo.compra_detalle_id', '=', 'compra_detalles.id')
            ->where('compras.empresa_id', $producto->empresa_id)
            ->where('compra_detalles.producto_id', $producto->id)
            ->orderByDesc('compras.fecha')
            ->orderByDesc('compras.creado_en')
            ->limit($limite)
            ->get([
                'compra_detalles.id',
                'compra_detalles.cantidad',
                'compra_detalles.costo_unitario',
                'compra_detalles.total',
                'compras.fecha',
                'compras.serie_numero as documento',
                'compras.estado',
                'proveedores.razon_social as proveedor',
                'producto_presentaciones.nombre as presentacion',
                'capas_costo.cantidad_restante as stock_restante',
            ])
            ->map(fn ($fila) => [
                'id' => $fila->id,
                'fecha' => $fila->fecha,
                'documento' => $fila->documento,
                'proveedor' => $fila->proveedor,
                'presentacion' => $fila->presentacion,
                'anulada' => $fila->estado === 'anulada',
                'stock_restante' => $fila->stock_restante !== null ? (float) $fila->stock_restante : null,
                'cantidad' => (float) $fila->cantidad,
                'costo_unitario' => (float) $fila->costo_unitario,
                'total' => (float) $fila->total,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function ventas(Producto $producto, int $limite = self::LIMITE): array
    {
        $nombres = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito', '08' => 'Nota de débito'];

        return ComprobanteDetalle::query()
            ->join('comprobantes', 'comprobantes.id', '=', 'comprobante_detalles.comprobante_id')
            ->leftJoin('producto_presentaciones', 'producto_presentaciones.id', '=', 'comprobante_detalles.presentacion_id')
            ->where('comprobantes.empresa_id', $producto->empresa_id)
            ->where('comprobante_detalles.producto_id', $producto->id)
            ->orderByDesc('comprobantes.fecha_emision')
            ->orderByDesc('comprobantes.hora_emision')
            ->limit($limite)
            ->get([
                'comprobante_detalles.id',
                'comprobante_detalles.cantidad',
                'comprobante_detalles.precio_unitario',
                'comprobante_detalles.total',
                'comprobantes.id as comprobante_id',
                'comprobantes.fecha_emision',
                'comprobantes.tipo_comprobante_codigo',
                'comprobantes.serie',
                'comprobantes.correlativo',
                'comprobantes.cliente_nombre',
                'comprobantes.estado',
                'producto_presentaciones.nombre as presentacion',
            ])
            ->map(fn ($fila) => [
                'id' => $fila->id,
                'comprobante_id' => $fila->comprobante_id,
                'fecha' => $fila->fecha_emision,
                'tipo' => $nombres[$fila->tipo_comprobante_codigo] ?? $fila->tipo_comprobante_codigo,
                'documento' => $fila->serie.'-'.str_pad((string) $fila->correlativo, 6, '0', STR_PAD_LEFT),
                'cliente' => filled($fila->cliente_nombre) ? $fila->cliente_nombre : 'Clientes varios',
                'presentacion' => $fila->presentacion,
                'anulada' => $fila->estado === 'anulado',
                'cantidad' => (float) $fila->cantidad,
                'precio_unitario' => (float) $fila->precio_unitario,
                'total' => (float) $fila->total,
            ])
            ->values()
            ->all();
    }
}
