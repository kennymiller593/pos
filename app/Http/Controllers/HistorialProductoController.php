<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\HistorialProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Últimas compras y ventas de un producto. Cada parte según el permiso: las compras muestran costos
 * (stock.costos) y las ventas, comprobantes (comprobantes.ver).
 */
class HistorialProductoController extends Controller
{
    public function __invoke(Request $request, Producto $producto, HistorialProductoService $historial): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($producto->empresa_id === $usuario->empresa_id, 403);

        $puedeCompras = $usuario->can('stock.costos');
        $puedeVentas = $usuario->can('comprobantes.ver');
        abort_unless($puedeCompras || $puedeVentas, 403);

        return response()->json([
            'puede' => ['compras' => $puedeCompras, 'ventas' => $puedeVentas],
            'compras' => $puedeCompras ? $historial->compras($producto) : [],
            'ventas' => $puedeVentas ? $historial->ventas($producto) : [],
        ]);
    }
}
