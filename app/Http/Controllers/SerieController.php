<?php

namespace App\Http\Controllers;

use App\Models\Compra;
use App\Models\Comprobante;
use App\Models\Producto;
use App\Models\ProductoSerie;
use App\Models\SerieMovimiento;
use App\Models\Transferencia;
use App\Services\SerieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta de series / IMEI (módulo opcional): el listado sigue la sucursal elegida, como el resto del sistema;
 * la búsqueda exacta de una serie mira TODA la empresa, para saber dónde está o a quién se vendió.
 */
class SerieController extends Controller
{
    private const TIPOS_COMPROBANTE = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito'];

    public function __construct(private readonly SerieService $series) {}

    public function index(Request $request): Response
    {
        $this->validarConsulta($request);
        $empresaId = $request->user()->empresa_id;

        $filtros = [];
        if (isset(ProductoSerie::ESTADOS[$request->query('estado')])) {
            $filtros['estado'] = $request->query('estado');
        }
        if (Str::isUuid((string) $request->query('producto'))) {
            $filtros['producto'] = $request->query('producto');
        }
        // serie exacta a buscar (o segundo IMEI): /series?numero=356789012345678
        $numero = Str::limit(trim((string) $request->query('numero')), SerieService::MAX_LARGO, '');

        $series = ProductoSerie::query()
            ->where('empresa_id', $empresaId)
            // la sucursal elegida en el selector (null = todas las que puede ver)
            ->when($this->sucursalesConsulta($request), fn ($q, $ids) => $q->whereIn('sucursal_id', $ids))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['producto'] ?? null, fn ($q, $producto) => $q->where('producto_id', $producto))
            ->with(['producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'codigo_interno'), 'sucursal:id,nombre'])
            ->orderByDesc('actualizado_en')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ProductoSerie $s) => [
                'id' => $s->id,
                'numero_serie' => $s->numero_serie,
                'serie_alterna' => $s->serie_alterna,
                'estado' => $s->estado,
                'producto' => $s->producto?->nombre,
                'sucursal' => $s->sucursal?->nombre,
                'actualizado_en' => $s->actualizado_en?->toIso8601String(),
            ]);

        return Inertia::render('Series/Index', [
            'series' => $series,
            'filtros' => $filtros,
            'numero' => $numero,
            // fichas de la serie buscada (normalmente una; la misma serie puede existir en dos productos)
            'fichas' => $numero !== '' ? $this->fichas($request, $numero) : [],
            'estados' => ProductoSerie::ESTADOS,
            // para el filtro: solo los productos que tienen unidades registradas
            'productos' => Producto::withTrashed()
                ->where('empresa_id', $empresaId)
                ->whereIn('id', ProductoSerie::where('empresa_id', $empresaId)->select('producto_id'))
                ->orderBy('nombre')
                ->limit(500)
                ->get(['id', 'nombre']),
        ]);
    }

    /** Lo mismo que la ficha de la pantalla, en JSON: /series/buscar?numero=... */
    public function buscar(Request $request): JsonResponse
    {
        $this->validarConsulta($request);
        $numero = Str::limit(trim((string) $request->query('numero')), SerieService::MAX_LARGO, '');

        return response()->json(['numero' => ProductoSerie::normalizar($numero), 'fichas' => $numero !== '' ? $this->fichas($request, $numero) : []]);
    }

    /** Los parámetros de la consulta son textos simples: un ?numero[]=x responde con error de validación, no con un 500. */
    private function validarConsulta(Request $request): void
    {
        $request->validate([
            'numero' => ['nullable', 'string', 'max:200'],
            'estado' => ['nullable', 'string', 'max:20'],
            'producto' => ['nullable', 'string', 'max:40'],
        ]);
    }

    /** Ficha de cada unidad con esa serie: producto, estado, dónde está, con qué compra entró, a quién se vendió y su historia. */
    private function fichas(Request $request, string $numero): array
    {
        $usuario = $request->user();
        $encontradas = $this->series->buscar($usuario->empresa_id, $numero);

        if ($encontradas->isEmpty()) {
            return [];
        }

        $encontradas->load([
            'producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'codigo_interno', 'imagen_url'),
            'sucursal:id,nombre',
            'compraDetalle.compra' => fn ($q) => $q->with(['proveedor' => fn ($p) => $p->withTrashed()->select('id', 'razon_social')]),
            'comprobanteDetalle.comprobante',
            'movimientos' => fn ($q) => $q->with(['usuario:id,nombre_completo', 'sucursal:id,nombre'])->orderBy('creado_en')->orderBy('id'),
        ]);

        // los documentos que nombra la historia (ventas, notas, compras), todos de esta empresa
        $referencias = $encontradas->flatMap(fn ($s) => $s->movimientos->pluck('referencia_id'))->filter()->unique()->values();
        $comprobantes = Comprobante::where('empresa_id', $usuario->empresa_id)->whereIn('id', $referencias)
            ->get(['id', 'tipo_comprobante_codigo', 'serie', 'correlativo', 'cliente_nombre'])->keyBy('id');
        $compras = Compra::where('empresa_id', $usuario->empresa_id)->whereIn('id', $referencias)
            ->get(['id', 'serie_numero', 'fecha'])->keyBy('id');

        $verCostos = $usuario->can('stock.costos');
        $verComprobantes = $usuario->can('comprobantes.ver');
        // proveedor y documento de compra: solo para quien ve compras
        $verCompras = $usuario->can('compras.ver');

        return $encontradas->map(function (ProductoSerie $s) use ($comprobantes, $compras, $verCostos, $verComprobantes, $verCompras) {
            $compra = $verCompras ? $s->compraDetalle?->compra : null;
            $venta = $s->estado === 'vendida' ? $s->comprobanteDetalle?->comprobante : null;

            return [
                'id' => $s->id,
                'numero_serie' => $s->numero_serie,
                'serie_alterna' => $s->serie_alterna,
                'estado' => $s->estado,
                'producto' => [
                    'id' => $s->producto?->id,
                    'nombre' => $s->producto?->nombre,
                    'codigo_interno' => $s->producto?->codigo_interno,
                    'imagen_url' => $s->producto?->imagen_url,
                ],
                'sucursal' => $s->sucursal?->nombre,
                // en tránsito: a qué sucursal va (la de arriba es de la que salió)
                'destino' => $s->estado === 'en_transito'
                    ? Transferencia::with('sucursalDestino:id,nombre')->find($s->movimientos->where('tipo', 'transferencia_salida')->last()?->referencia_id)?->sucursalDestino?->nombre
                    : null,
                'costo_unitario' => $verCostos && $s->costo_unitario !== null ? (float) $s->costo_unitario : null,
                'compra' => $compra ? [
                    'documento' => $compra->serie_numero,
                    'fecha' => $compra->fecha?->toDateString(),
                    'proveedor' => $compra->proveedor?->razon_social,
                    'anulada' => $compra->estado !== 'registrada',
                ] : null,
                'venta' => $venta ? [
                    'id' => $verComprobantes ? $venta->id : null,
                    'tipo' => self::TIPOS_COMPROBANTE[$venta->tipo_comprobante_codigo] ?? 'Comprobante',
                    'numero' => self::numero($venta),
                    'cliente' => $venta->cliente_nombre ?: 'Cliente sin identificar',
                    'cliente_documento' => $venta->cliente_numero_doc,
                    'fecha' => $venta->fecha_emision?->toDateString(),
                    'hora' => $venta->hora_emision ? substr((string) $venta->hora_emision, 0, 5) : null,
                    'ticket' => $verComprobantes ? route('comprobantes.ticket', $venta) : null,
                ] : null,
                // sin permiso de compras no se sabe con qué documento entró (tampoco se dice "ajuste")
                'ver_compra' => $verCompras,
                'historial' => $this->historial($s->movimientos, $comprobantes, $verCompras ? $compras : collect()),
            ];
        })->all();
    }

    private function historial(Collection $movimientos, Collection $comprobantes, Collection $compras): array
    {
        return $movimientos->map(function (SerieMovimiento $m) use ($comprobantes, $compras) {
            $comprobante = $comprobantes->get($m->referencia_id);
            $compra = $compras->get($m->referencia_id);

            $detalle = match (true) {
                $comprobante !== null => trim(self::numero($comprobante).($m->tipo === 'venta' && $comprobante->cliente_nombre ? " · {$comprobante->cliente_nombre}" : '')),
                $compra !== null => $compra->serie_numero ? "Compra {$compra->serie_numero}" : 'Compra del '.$compra->fecha?->format('d/m/Y'),
                default => null,
            };

            return [
                'id' => $m->id,
                'tipo' => $m->tipo,
                'titulo' => SerieMovimiento::TIPOS[$m->tipo] ?? $m->tipo,
                'detalle' => $detalle,
                'sucursal' => $m->sucursal?->nombre,
                'usuario' => $m->usuario?->nombre_completo,
                'fecha' => $m->creado_en?->toIso8601String(),
            ];
        })->values()->all();
    }

    private static function numero(Comprobante $comprobante): string
    {
        return "{$comprobante->serie}-".str_pad((string) $comprobante->correlativo, 6, '0', STR_PAD_LEFT);
    }
}
