<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\CapaCosto;
use App\Models\Mezcla;
use App\Models\Producto;
use App\Models\Receta;
use App\Services\BusquedaProductos;
use App\Services\MezclaService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MezclaController extends Controller
{
    public function __construct(
        private readonly MezclaService $mezclas,
        private readonly BusquedaProductos $busqueda,
    ) {}

    public function index(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;

        // filtros: producto (obtenido o insumo) y rango de fechas; un valor mal formado se ignora
        $filtros = [];
        foreach (['desde', 'hasta'] as $campo) {
            $valor = (string) $request->query($campo);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor)) {
                $filtros[$campo] = $valor;
            }
        }
        $buscar = Str::limit(trim((string) $request->query('buscar')), 60, '');
        if ($buscar !== '') {
            $filtros['buscar'] = $buscar;
        }
        $productosBuscados = [];
        if ($buscar !== '' && ($palabras = BusquedaProductos::palabras($buscar)) !== []) {
            $productosBuscados = $this->busqueda->aplicar(Producto::withTrashed()->where('productos.empresa_id', $empresaId), $palabras)
                ->limit(500)->toBase()->pluck('productos.id')->all();
        }

        $mezclas = Mezcla::query()
            ->where('empresa_id', $empresaId)
            ->when($this->sucursalesConsulta($request), fn ($q, $ids) => $q->whereIn('sucursal_id', $ids))
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->whereDate('creado_en', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->whereDate('creado_en', '<=', $hasta))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('producto_id', $productosBuscados)
                ->orWhereHas('insumos', fn ($i) => $i->whereIn('producto_id', $productosBuscados))
                ->orWhereRaw("'MZ-' || lpad(numero::text, 4, '0') LIKE ?", ['%'.mb_strtoupper($buscar).'%'])))
            ->with([
                'sucursal:id,nombre',
                'usuario:id,nombre_completo',
                'anuladaPor:id,nombre_completo',
                'producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'imagen_url'),
                'presentacion:id,nombre',
                'lote:id,numero_lote,fecha_vencimiento',
                'insumos.producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
                'insumos.presentacion:id,nombre',
            ])
            ->latest('creado_en')
            ->paginate(10)
            ->withQueryString();

        $verCostos = $request->user()->can('stock.costos');
        $mezclas->getCollection()->transform(fn (Mezcla $m) => [
            'id' => $m->id,
            'codigo' => $m->codigo(),
            'creado_en' => $m->creado_en,
            'sucursal' => $m->sucursal?->only('id', 'nombre'),
            'usuario' => $m->usuario?->only('nombre_completo'),
            'anulada_por' => $m->anuladaPor?->only('nombre_completo'),
            'anulada_en' => $m->anulada_en,
            'motivo_anulacion' => $m->motivo_anulacion,
            'estado' => $m->estado,
            'observacion' => $m->observacion,
            'producto' => $m->producto?->only('id', 'nombre', 'imagen_url'),
            'presentacion' => $m->presentacion?->nombre,
            'lote' => $m->lote?->only('numero_lote', 'fecha_vencimiento'),
            'cantidad' => (float) $m->cantidad,
            'cantidad_base' => (float) $m->cantidad_base,
            'costo_insumos' => $verCostos ? (float) $m->costo_insumos : null,
            'otros_costos' => $verCostos ? (float) $m->otros_costos : null,
            'costo_total' => $verCostos ? (float) $m->costo_total : null,
            'costo_unitario' => $verCostos ? (float) $m->costo_unitario : null,
            'insumos' => $m->insumos->map(fn ($i) => [
                'id' => $i->id,
                'producto' => $i->producto?->only('id', 'nombre'),
                'presentacion' => $i->presentacion?->nombre,
                'cantidad' => (float) $i->cantidad,
                'cantidad_base' => (float) $i->cantidad_base,
                'costo_unitario' => $verCostos ? (float) $i->costo_unitario : null,
                'total' => $verCostos ? (float) $i->total : null,
                'coincide' => in_array($i->producto_id, $productosBuscados, true),
            ])->values(),
        ]);

        return Inertia::render('Mezclas/Index', [
            'mezclas' => $mezclas,
            'filtros' => $filtros,
        ]);
    }

    public function crear(Request $request): Response
    {
        $empresaId = $request->user()->empresa_id;
        $sucursalId = $this->sucursalDeTrabajo($request) ?? $this->sucursalPorDefecto($request);
        $verCostos = $request->user()->can('stock.costos');

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->where('controla_stock', true)
            ->withSum(['stock as stock' => fn ($q) => $q->where('sucursal_id', $sucursalId)], 'cantidad')
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'controla_lote', 'permite_fraccion', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        // costo promedio de lo que queda en stock (para estimar el costo de la mezcla antes de confirmar)
        $costos = $verCostos
            ? CapaCosto::query()->where('empresa_id', $empresaId)->where('sucursal_id', $sucursalId)->where('cantidad_restante', '>', 0)
                ->selectRaw('producto_id, sum(cantidad_restante * costo_unitario) / sum(cantidad_restante) as costo')
                ->groupBy('producto_id')->toBase()->pluck('costo', 'producto_id')
            : collect();

        // fórmulas guardadas: insumos por cada `rinde_base` unidades del producto
        $recetas = Receta::query()->where('empresa_id', $empresaId)->with('insumos')->get()
            ->mapWithKeys(fn ($r) => [$r->producto_id => [
                'rinde_base' => (float) $r->rinde_base,
                'otros_costos' => (float) $r->otros_costos,
                'insumos' => $r->insumos->map(fn ($i) => ['producto_id' => $i->producto_id, 'presentacion_id' => $i->presentacion_id, 'cantidad_base' => (float) $i->cantidad_base])->values(),
            ]]);

        return Inertia::render('Mezclas/Crear', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'controla_lote' => $p->controla_lote,
                'permite_fraccion' => $p->permite_fraccion,
                'imagen_url' => $p->imagen_url,
                'stock' => (float) ($p->stock ?? 0),
                'costo' => isset($costos[$p->id]) ? round((float) $costos[$p->id], 4) : null,
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'factor_conversion' => (float) $pres->factor_conversion,
                    'precio_venta' => (float) $pres->precio_venta,
                    'codigo_barras' => $pres->codigo_barras,
                    'es_default' => (bool) $pres->es_default,
                ])->values(),
            ]),
            'recetas' => $recetas,
            'siguienteCodigo' => 'MZ-'.str_pad((string) ((int) Mezcla::where('empresa_id', $empresaId)->max('numero') + 1), 4, '0', STR_PAD_LEFT),
            // sucursal donde se prepara: la del selector; viendo "todas", se elige en el formulario
            'sucursalNombre' => $this->contextoSucursal($request)->nombreDe($this->sucursalDeTrabajo($request)),
            'sucursales' => $this->sucursalDeTrabajo($request) ? [] : $this->contextoSucursal($request)->accesibles->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre])->values(),
            'sucursalId' => $sucursalId,
            'verCostos' => $verCostos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'producto_id' => ['required', 'uuid'],
            'presentacion_id' => ['nullable', 'uuid'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'numero_lote' => ['nullable', 'string', 'max:50'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'otros_costos' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:200'],
            'guardar_formula' => ['nullable', 'boolean'],
            'insumos' => ['required', 'array', 'min:1'],
            'insumos.*.producto_id' => ['required', 'uuid'],
            'insumos.*.presentacion_id' => ['nullable', 'uuid'],
            'insumos.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'producto_id.required' => 'Elige el producto que se obtiene.',
            'cantidad.required' => 'Indica cuánto se obtiene.',
            'cantidad.gt' => 'La cantidad obtenida debe ser mayor a 0.',
            'insumos.required' => 'Agrega al menos un insumo.',
            'insumos.*.cantidad.gt' => 'La cantidad de cada insumo debe ser mayor a 0.',
        ]);

        $sucursalId = $this->sucursalElegida($request, 'sucursal_id');
        if (! $sucursalId) {
            return back()->with('error', 'Elige la sucursal donde se prepara la mezcla.');
        }

        try {
            $mezcla = $this->mezclas->registrar($request->user(), $sucursalId, $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la mezcla. Intenta de nuevo.');
        }

        $costo = $request->user()->can('stock.costos') ? ' Costo: S/ '.number_format((float) $mezcla->costo_unitario, 2).' por unidad.' : '';

        return redirect()->route('mezclas.index')
            ->with('success', "Mezcla {$mezcla->codigo()} registrada. Los insumos salieron del stock y entró lo obtenido.{$costo}");
    }

    public function anular(Request $request, Mezcla $mezcla): RedirectResponse
    {
        abort_unless($mezcla->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:250'],
        ], [
            'motivo.required' => 'Indica el motivo de la anulación.',
        ]);

        try {
            $this->mezclas->anular($mezcla, $request->user(), $datos['motivo']);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo anular la mezcla. Intenta de nuevo.');
        }

        return back()->with('success', 'Mezcla anulada. Los insumos volvieron al stock y se retiró lo obtenido.');
    }

    public function pdf(Request $request, Mezcla $mezcla)
    {
        abort_unless($mezcla->empresa_id === $request->user()->empresa_id, 403);

        $mezcla->load([
            'sucursal:id,nombre',
            'usuario:id,nombre_completo',
            'producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
            'presentacion:id,nombre',
            'lote:id,numero_lote,fecha_vencimiento',
            'insumos.producto' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
            'insumos.presentacion:id,nombre',
        ]);

        return SnappyPdf::loadView('pdf.mezcla', [
            'mezcla' => $mezcla,
            'empresa' => $request->user()->empresa,
            'verCostos' => $request->user()->can('stock.costos'),
        ])->setPaper('a4')->download('mezcla-'.strtolower($mezcla->codigo()).'.pdf');
    }
}
