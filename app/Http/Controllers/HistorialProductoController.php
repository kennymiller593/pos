<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\HistorialProductoService;
use App\Support\ContextoSucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Últimas compras y ventas de un producto. Cada parte según el permiso: las compras muestran costos
 * (stock.costos) y las ventas, comprobantes (comprobantes.ver). Solo de la sucursal elegida en el
 * selector, o de todas las que el usuario puede ver.
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

        $contexto = ContextoSucursal::de($request);
        $sucursales = $contexto->consultaIds();

        return response()->json([
            'puede' => ['compras' => $puedeCompras, 'ventas' => $puedeVentas],
            // con varias sucursales la ventana muestra de cual es cada movimiento
            'multisucursal' => $contexto->multisucursal,
            'compras' => $puedeCompras ? $historial->compras($producto, $sucursales) : [],
            'ventas' => $puedeVentas ? $historial->ventas($producto, $sucursales) : [],
        ]);
    }
}
