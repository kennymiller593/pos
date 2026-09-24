<?php

namespace App\Http\Controllers;

use App\Models\CapaCosto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Services\InventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $sucursalId = $this->sucursalDelUsuario($request);
        $filtros = $request->only(['buscar', 'bajos', 'vencen']);

        $capasPorVencer = fn ($q) => $q
            ->where('sucursal_id', $sucursalId)
            ->where('cantidad_restante', '>', 0)
            ->whereHas('lote', fn ($l) => $l
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<=', now()->addDays(30)->toDateString()));

        $condicionBajo = 'COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id and s.sucursal_id = ?), 0) <= 0'
            . ' or (stock_minimo > 0 and COALESCE((select sum(s.cantidad) from stock s where s.producto_id = productos.id and s.sucursal_id = ?), 0) <= stock_minimo)';

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->when($filtros['vencen'] ?? null, fn ($q) => $q->whereHas('capasCosto', $capasPorVencer))
            ->with(['categoria:id,nombre', 'unidadBase:codigo,nombre'])
            ->withSum(['stock as stock' => fn ($q) => $q->where('sucursal_id', $sucursalId)], 'cantidad')
            ->addSelect(['valor_inventario' => CapaCosto::query()
                ->selectRaw('COALESCE(SUM(cantidad_restante * costo_unitario), 0)')
                ->whereColumn('producto_id', 'productos.id')
                ->where('sucursal_id', $sucursalId)
                ->where('cantidad_restante', '>', 0),
            ])
            ->when($filtros['buscar'] ?? null, fn ($q, $buscar) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', "%{$buscar}%")
                ->orWhere('codigo_interno', 'ilike', "%{$buscar}%")))
            ->when($filtros['bajos'] ?? null, fn ($q) => $q->whereRaw("({$condicionBajo})", [$sucursalId, $sucursalId]))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        $resumen = [
            'valor_total' => (float) CapaCosto::query()
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('cantidad_restante', '>', 0)
                ->selectRaw('COALESCE(SUM(cantidad_restante * costo_unitario), 0) as valor')
                ->value('valor'),
            'bajos' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->whereRaw("({$condicionBajo})", [$sucursalId, $sucursalId])
                ->count(),
            'productos' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->count(),
            'por_vencer' => Producto::query()
                ->where('empresa_id', $empresaId)
                ->where('activo', true)
                ->where('controla_stock', true)
                ->whereHas('capasCosto', $capasPorVencer)
                ->count(),
        ];

        return Inertia::render('Stock/Index', [
            'productos' => $productos,
            'filtros' => $filtros,
            'resumen' => $resumen,
            'sucursal' => Sucursal::find($sucursalId)?->nombre,
        ]);
    }

    public function kardex(Request $request, Producto $producto): JsonResponse
    {
        abort_unless($producto->empresa_id === $request->user()->empresa_id, 403);

        $sucursalId = $this->sucursalDelUsuario($request);

        $lotes = Lote::query()
            ->where('producto_id', $producto->id)
            ->where('sucursal_id', $sucursalId)
            ->withSum('capasCosto as restante', 'cantidad_restante')
            ->orderByRaw('fecha_vencimiento asc nulls last')
            ->get()
            ->filter(fn ($lote) => (float) ($lote->restante ?? 0) > 0)
            ->values()
            ->map(fn ($lote) => [
                'id' => $lote->id,
                'numero_lote' => $lote->numero_lote,
                'fecha_vencimiento' => $lote->fecha_vencimiento?->toDateString(),
                'restante' => (float) $lote->restante,
            ]);

        return response()->json([
            'lotes' => $lotes,
            'movimientos' => MovimientoInventario::query()
                ->where('producto_id', $producto->id)
                ->where('sucursal_id', $sucursalId)
                ->with('usuario:id,nombre_completo')
                ->latest('creado_en')
                ->limit(30)
                ->get(['id', 'tipo', 'cantidad', 'costo_unitario', 'usuario_id', 'creado_en']),
        ]);
    }

    public function ajustar(Request $request, Producto $producto): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($producto->empresa_id === $usuario->empresa_id, 403);

        if (! $producto->controla_stock) {
            return back()->with('error', 'Este producto no controla stock.');
        }

        $datos = $request->validate([
            'direccion' => ['required', 'in:entrada,salida'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'costo_unitario' => ['required_if:direccion,entrada', 'nullable', 'numeric', 'min:0'],
            'numero_lote' => ['nullable', 'string', 'max:50'],
            'fecha_vencimiento' => ['nullable', 'date'],
        ], [
            'cantidad.required' => 'Ingresa la cantidad.',
            'cantidad.gt' => 'La cantidad debe ser mayor a 0.',
            'costo_unitario.required_if' => 'Indica el costo unitario del ingreso.',
        ]);

        if ($datos['direccion'] === 'entrada' && $producto->controla_lote && blank($datos['numero_lote'] ?? null)) {
            return back()->with('error', 'Este producto controla lotes: indica el número de lote.');
        }

        if (! $producto->permite_fraccion && fmod((float) $datos['cantidad'], 1) != 0) {
            return back()->with('error', 'Este producto no permite cantidades fraccionadas.');
        }

        $sucursalId = $this->sucursalDelUsuario($request);
        $cantidad = round((float) $datos['cantidad'], 3);

        if ($datos['direccion'] === 'salida') {
            $disponible = $this->inventario->stockDisponible($producto->id, $sucursalId);

            if ($cantidad > $disponible) {
                return back()->with('error', "No puedes retirar más de lo disponible ({$disponible}).");
            }
        }

        try {
            DB::transaction(function () use ($datos, $producto, $usuario, $sucursalId, $cantidad) {
                if ($datos['direccion'] === 'entrada') {
                    $loteId = null;
                    if ($producto->controla_lote && filled($datos['numero_lote'] ?? null)) {
                        $loteId = $this->inventario->obtenerLote(
                            $producto,
                            $sucursalId,
                            $datos['numero_lote'],
                            $datos['fecha_vencimiento'] ?? null,
                        )->id;
                    }

                    // nueva capa de costo para mantener el FIFO consistente
                    $costo = (float) $datos['costo_unitario'];
                    $this->inventario->ingresarCapa($producto, $sucursalId, $cantidad, $costo, loteId: $loteId);
                    $this->inventario->incrementarStock($producto->empresa_id, $producto->id, $sucursalId, $cantidad);
                    $this->inventario->registrarMovimiento(
                        $producto->empresa_id, $sucursalId, $producto->id, 'ajuste', $cantidad, $costo, usuarioId: $usuario->id, loteId: $loteId,
                    );

                    return;
                }

                // salida (merma): consume capas FIFO igual que una venta
                $consumo = $this->inventario->consumirFifo($producto->id, $sucursalId, $cantidad);
                $this->inventario->descontarStock($producto->id, $sucursalId, $cantidad);
                $this->inventario->registrarMovimiento(
                    $producto->empresa_id,
                    $sucursalId,
                    $producto->id,
                    'merma',
                    $cantidad,
                    $cantidad > 0 ? $consumo['costo_total'] / $cantidad : 0,
                    usuarioId: $usuario->id,
                );
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar el ajuste. Intenta de nuevo.');
        }

        return back()->with('success', $datos['direccion'] === 'entrada'
            ? 'Entrada registrada. Stock actualizado.'
            : 'Salida registrada. Stock actualizado.');
    }

    private function sucursalDelUsuario(Request $request): ?string
    {
        return $this->sucursalDeTrabajo($request);
    }
}
