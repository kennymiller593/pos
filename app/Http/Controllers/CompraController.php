<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\CuentaPorPagar;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Sucursal;
use App\Models\TipoComprobante;
use App\Services\InventarioService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompraController extends Controller
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

    public function index(Request $request): Response
    {
        $compras = Compra::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->with([
                'proveedor' => fn ($q) => $q->withTrashed()->select('id', 'razon_social'),
                'usuario:id,nombre_completo',
                'detalles:id,compra_id,producto_id,presentacion_id,cantidad,costo_unitario,total',
                'detalles.producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
                'detalles.presentacion:id,nombre',
            ])
            ->latest('creado_en')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Compras/Index', ['compras' => $compras]);
    }

    public function pdf(Request $request, Compra $compra)
    {
        abort_unless($compra->empresa_id === $request->user()->empresa_id, 403);

        $compra->load([
            'proveedor' => fn ($q) => $q->withTrashed(),
            'usuario:id,nombre_completo',
            'sucursal:id,nombre',
            'detalles.producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
            'detalles.presentacion:id,nombre',
        ]);

        $nombre = 'compra-' . $compra->fecha->format('Y-m-d') . '-' . substr($compra->id, -6) . '.pdf';

        return SnappyPdf::loadView('pdf.compra', [
            'compra' => $compra,
            'empresa' => $request->user()->empresa,
        ])
            ->setOption('encoding', 'utf-8')
            ->setOption('page-size', 'A4')
            ->download($nombre);
    }

    public function crear(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'controla_stock', 'controla_lote', 'permite_fraccion'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        return Inertia::render('Compras/Crear', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'controla_stock' => $p->controla_stock,
                'controla_lote' => $p->controla_lote,
                'permite_fraccion' => $p->permite_fraccion,
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'factor_conversion' => (float) $pres->factor_conversion,
                    'es_default' => $pres->es_default,
                ]),
            ]),
            'tiposComprobante' => TipoComprobante::whereIn('codigo', ['01', '03', '00'])->orderBy('codigo')->get(['codigo', 'nombre']),
            'sucursalDestino' => Sucursal::find($this->sucursalDeTrabajo($request))?->nombre,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $empresaId = $usuario->empresa_id;

        $datos = $request->validate([
            'proveedor_id' => [
                $request->boolean('es_credito') ? 'required' : 'nullable',
                'uuid', Rule::exists('proveedores', 'id')->where('empresa_id', $empresaId),
            ],
            'tipo_comprobante_codigo' => ['nullable', Rule::exists('tipos_comprobante', 'codigo')],
            'serie_numero' => ['nullable', 'string', 'max:20'],
            'fecha' => ['required', 'date'],
            'es_credito' => ['required', 'boolean'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.costo_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.numero_lote' => ['nullable', 'string', 'max:50'],
            'items.*.fecha_vencimiento' => ['nullable', 'date'],
        ], [
            'proveedor_id.required' => 'La compra a crédito necesita un proveedor para registrar la deuda.',
            'fecha.required' => 'Indica la fecha de la compra.',
            'fecha_vencimiento.after_or_equal' => 'El vencimiento no puede ser anterior a la fecha de compra.',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.cantidad.gt' => 'La cantidad debe ser mayor a 0.',
            'items.*.costo_unitario.min' => 'El costo no puede ser negativo.',
        ]);

        $sucursalId = $this->sucursalDeTrabajo($request);

        if (! $sucursalId) {
            return back()->with('error', 'No tienes una sucursal asignada.');
        }

        $presentaciones = ProductoPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', collect($datos['items'])->pluck('presentacion_id'))
            ->with('producto:id,empresa_id,nombre,controla_stock,controla_lote,permite_fraccion')
            ->get()
            ->keyBy('id');

        // preparar lineas
        $lineas = [];
        $total = 0.0;

        foreach ($datos['items'] as $item) {
            $presentacion = $presentaciones->get($item['presentacion_id']);
            if (! $presentacion) {
                return back()->with('error', 'Uno de los productos elegidos ya no existe.');
            }

            $producto = $presentacion->producto;
            $cantidad = (float) $item['cantidad'];

            if (! $producto->permite_fraccion && fmod($cantidad, 1) != 0) {
                return back()->with('error', "\"{$producto->nombre}\" no permite cantidades fraccionadas.");
            }

            if ($producto->controla_lote && blank($item['numero_lote'] ?? null)) {
                return back()->with('error', "\"{$producto->nombre}\" controla lotes: indica el número de lote.");
            }

            $costo = (float) $item['costo_unitario'];
            $factor = (float) $presentacion->factor_conversion;
            $totalLinea = round($cantidad * $costo, 2);
            $total += $totalLinea;

            $lineas[] = [
                'presentacion' => $presentacion,
                'producto' => $producto,
                'cantidad' => $cantidad,
                'costo' => $costo,
                'total' => $totalLinea,
                'cantidad_base' => round($cantidad * $factor, 3),
                'costo_base' => $factor > 0 ? round($costo / $factor, 6) : $costo,
                'numero_lote' => $item['numero_lote'] ?? null,
                'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
            ];
        }

        try {
            DB::transaction(function () use ($datos, $lineas, $total, $usuario, $empresaId, $sucursalId) {
                $compra = Compra::create([
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'proveedor_id' => $datos['proveedor_id'] ?? null,
                    'usuario_id' => $usuario->id,
                    'tipo_comprobante_codigo' => $datos['tipo_comprobante_codigo'] ?? null,
                    'serie_numero' => $datos['serie_numero'] ?? null,
                    'fecha' => $datos['fecha'],
                    'total' => round($total, 2),
                    'es_credito' => $datos['es_credito'],
                ]);

                // la compra al credito genera la deuda con el proveedor
                if ($datos['es_credito']) {
                    CuentaPorPagar::create([
                        'empresa_id' => $empresaId,
                        'compra_id' => $compra->id,
                        'proveedor_id' => $datos['proveedor_id'],
                        'monto_total' => round($total, 2),
                        'fecha_vencimiento' => $datos['fecha_vencimiento'] ?? null,
                    ]);
                }

                foreach ($lineas as $linea) {
                    $loteId = null;
                    if ($linea['producto']->controla_lote && filled($linea['numero_lote'])) {
                        $loteId = $this->inventario->obtenerLote(
                            $linea['producto'],
                            $sucursalId,
                            $linea['numero_lote'],
                            $linea['fecha_vencimiento'],
                        )->id;
                    }

                    $detalle = $compra->detalles()->create([
                        'empresa_id' => $empresaId,
                        'producto_id' => $linea['producto']->id,
                        'presentacion_id' => $linea['presentacion']->id,
                        'lote_id' => $loteId,
                        'cantidad' => $linea['cantidad'],
                        'costo_unitario' => $linea['costo'],
                        'total' => $linea['total'],
                    ]);

                    if (! $linea['producto']->controla_stock) {
                        continue;
                    }

                    $this->inventario->ingresarCapa($linea['producto'], $sucursalId, $linea['cantidad_base'], $linea['costo_base'], $detalle->id, $loteId);
                    $this->inventario->incrementarStock($empresaId, $linea['producto']->id, $sucursalId, $linea['cantidad_base']);
                    $this->inventario->registrarMovimiento(
                        $empresaId,
                        $sucursalId,
                        $linea['producto']->id,
                        'compra',
                        $linea['cantidad_base'],
                        $linea['costo_base'],
                        $compra->id,
                        $usuario->id,
                        $loteId,
                    );
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la compra. Intenta de nuevo.');
        }

        return redirect()->route('compras.index')
            ->with('success', 'Compra registrada por S/ ' . number_format($total, 2) . '. Stock actualizado.');
    }
}
