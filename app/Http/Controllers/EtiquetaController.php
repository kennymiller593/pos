<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Support\CodigoBarras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Etiquetas con código de barras para imprimir en la ticketera o en una impresora de etiquetas. */
class EtiquetaController extends Controller
{
    /** Tope de etiquetas que se proponen de una sola compra (se puede subir a mano). */
    private const MAX_COPIAS_SUGERIDAS = 50;

    public function index(Request $request): Response
    {
        $empresa = $request->user()->empresa;

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        return Inertia::render('Productos/Etiquetas', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'imagen_url' => $p->imagen_url,
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'precio_venta' => (float) $pres->precio_venta,
                    'codigo_barras' => $pres->codigo_barras,
                    'es_default' => $pres->es_default,
                ]),
            ]),
            'inicial' => $this->inicial($request),
            'empresaNombre' => $empresa->nombre_comercial ?: $empresa->razon_social,
        ]);
    }

    /**
     * Con qué arranca la pantalla: los productos elegidos en la lista (?ids=a,b)
     * o lo que entró en una compra (?compra=), una etiqueta por unidad comprada.
     *
     * @return array{origen: ?string, items: list<array{presentacion_id: string, copias: int}>}
     */
    private function inicial(Request $request): array
    {
        $empresaId = $request->user()->empresa_id;

        if (Str::isUuid((string) $request->query('compra'))) {
            $compra = Compra::where('empresa_id', $empresaId)
                ->with('detalles:id,compra_id,presentacion_id,cantidad')
                ->find($request->query('compra'));

            if ($compra) {
                return [
                    'origen' => 'Compra del '.$compra->fecha->format('d/m/Y').($compra->serie_numero ? " · {$compra->serie_numero}" : ''),
                    'items' => $compra->detalles
                        ->filter(fn ($d) => $d->presentacion_id)
                        ->groupBy('presentacion_id')
                        ->map(fn ($lineas, $presentacionId) => [
                            'presentacion_id' => $presentacionId,
                            'copias' => (int) max(1, min(self::MAX_COPIAS_SUGERIDAS, ceil((float) $lineas->sum('cantidad')))),
                        ])
                        ->values()
                        ->all(),
                ];
            }
        }

        $ids = collect(explode(',', (string) $request->query('ids')))->filter(fn ($id) => Str::isUuid($id));

        if ($ids->isNotEmpty()) {
            return [
                'origen' => null,
                'items' => ProductoPresentacion::query()
                    ->where('empresa_id', $empresaId)
                    ->where('activo', true)
                    ->whereIn('producto_id', $ids)
                    ->orderByDesc('es_default')
                    ->get(['id', 'producto_id'])
                    ->unique('producto_id') // la presentación principal de cada producto
                    ->map(fn ($p) => ['presentacion_id' => $p->id, 'copias' => 1])
                    ->values()
                    ->all(),
            ];
        }

        return ['origen' => null, 'items' => []];
    }

    /** Código interno libre para el formulario de producto (se guarda recién al guardar el producto). */
    public function generar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'evitar' => ['nullable', 'array', 'max:50'],
            'evitar.*' => ['string', 'max:50'],
        ]);

        return response()->json(['codigo' => CodigoBarras::generar($request->user()->empresa_id, $datos['evitar'] ?? [])]);
    }

    /** Genera y guarda un código para las presentaciones indicadas que aún no tienen uno. */
    public function asignar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'presentacion_ids' => ['required', 'array', 'min:1', 'max:500'],
            'presentacion_ids.*' => ['uuid'],
        ]);

        $empresaId = $request->user()->empresa_id;

        $presentaciones = ProductoPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $datos['presentacion_ids'])
            ->where(fn ($q) => $q->whereNull('codigo_barras')->orWhere('codigo_barras', ''))
            ->get();

        $codigos = [];
        foreach ($presentaciones as $presentacion) {
            $codigo = CodigoBarras::generar($empresaId, array_values($codigos));
            $presentacion->update(['codigo_barras' => $codigo]);
            $codigos[$presentacion->id] = $codigo;
        }

        if ($codigos !== []) {
            Auditoria::registrar($request->user(), 'producto.codigos_generados', 'producto', null, ['cantidad' => count($codigos)]);
        }

        return response()->json(['codigos' => (object) $codigos]);
    }
}
