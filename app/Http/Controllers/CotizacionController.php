<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarCotizacionPorCorreo;
use App\Models\Cotizacion;
use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\TipoDocumentoIdentidad;
use App\Services\CotizacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CotizacionController extends Controller
{
    /** Días de validez que se proponen al crear una cotización. */
    private const VALIDEZ_DIAS = 7;

    public function __construct(private readonly CotizacionService $cotizaciones) {}

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['pendiente', 'vencida', 'convertida', 'anulada'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $hoy = now()->toDateString();
        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        $cotizaciones = Cotizacion::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->with([
                'detalles:id,cotizacion_id,descripcion,unidad_codigo,cantidad,precio_unitario,descuento,total,orden',
                'usuario:id,nombre_completo',
                'cliente' => fn ($q) => $q->withTrashed()->select('id', 'email', 'telefono'),
                'comprobante:id,serie,correlativo,tipo_comprobante_codigo',
            ])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cliente_nombre', 'ilike', "%{$buscar}%")
                ->orWhere('cliente_numero_doc', 'ilike', "{$buscar}%")
                ->when(preg_match('/(\d+)$/', $buscar, $m), fn ($q) => $q->orWhere('numero', (int) $m[1]))))
            // "vencida" no es un estado guardado: es una pendiente cuya validez ya pasó
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => match ($estado) {
                'pendiente' => $q->where('estado', 'pendiente')->where('valida_hasta', '>=', $hoy),
                'vencida' => $q->where('estado', 'pendiente')->where('valida_hasta', '<', $hoy),
                default => $q->where('estado', $estado),
            })
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->where('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha_emision', '<=', $hasta))
            ->orderByDesc('numero')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Cotizacion $c) => [
                'id' => $c->id,
                'codigo' => $c->codigo(),
                'fecha_emision' => $c->fecha_emision->toDateString(),
                'valida_hasta' => $c->valida_hasta->toDateString(),
                'estado' => $c->estadoVisible(),
                'cliente_nombre' => $c->cliente_nombre,
                'cliente_numero_doc' => $c->cliente_numero_doc,
                'cliente_email' => $c->cliente?->email,
                'cliente_telefono' => $c->cliente?->telefono,
                'tiempo_entrega' => $c->tiempo_entrega,
                'direccion_envio' => $c->direccion_envio,
                'es_credito' => $c->es_credito,
                'observaciones' => $c->observaciones,
                'total' => (float) $c->total,
                'vendedor' => $c->usuario?->nombre_completo,
                'comprobante' => $c->comprobante
                    ? "{$c->comprobante->serie}-".str_pad((string) $c->comprobante->correlativo, 6, '0', STR_PAD_LEFT)
                    : null,
                'enlace_publico' => self::enlacePublico($c),
                'detalles' => $c->detalles->map(fn ($d) => [
                    'id' => $d->id,
                    'descripcion' => $d->descripcion,
                    'unidad_codigo' => trim((string) $d->unidad_codigo),
                    'cantidad' => (float) $d->cantidad,
                    'precio_unitario' => (float) $d->precio_unitario,
                    'descuento' => (float) $d->descuento,
                    'total' => (float) $d->total,
                ]),
            ]);

        $empresa = $request->user()->empresa;

        return Inertia::render('Cotizaciones/Index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => [
                'buscar' => $buscar,
                'estado' => $filtros['estado'] ?? '',
                'desde' => $filtros['desde'] ?? '',
                'hasta' => $filtros['hasta'] ?? '',
            ],
            'empresaNombre' => $empresa->nombre_comercial ?: $empresa->razon_social,
        ]);
    }

    public function crear(Request $request): Response
    {
        // "Duplicar": la cotización nueva arranca con el contenido de otra
        $origen = null;
        if ($request->filled('duplicar')) {
            $origen = Cotizacion::where('empresa_id', $request->user()->empresa_id)->with(['detalles', 'cliente'])->find($request->query('duplicar'));
        }

        return $this->formulario($request, null, $origen);
    }

    public function editar(Request $request, Cotizacion $cotizacion): Response|RedirectResponse
    {
        abort_unless($cotizacion->empresa_id === $request->user()->empresa_id, 403);

        if ($cotizacion->estado !== 'pendiente') {
            return redirect()->route('cotizaciones.index')->with('error', 'Solo se puede editar una cotización pendiente. Duplícala para hacer una nueva.');
        }

        return $this->formulario($request, $cotizacion->load(['detalles', 'cliente']), null);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->guardar($request, null);
    }

    public function update(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless($cotizacion->empresa_id === $request->user()->empresa_id, 403);

        return $this->guardar($request, $cotizacion);
    }

    public function anular(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless($cotizacion->empresa_id === $request->user()->empresa_id, 403);

        try {
            $this->cotizaciones->anular($cotizacion, $request->user());
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Cotización {$cotizacion->codigo()} anulada.");
    }

    public function pdf(Request $request, Cotizacion $cotizacion)
    {
        abort_unless($cotizacion->empresa_id === $request->user()->empresa_id, 403);

        return $this->cotizaciones->pdf($cotizacion)->inline("{$cotizacion->codigo()}.pdf");
    }

    /** PDF para el cliente final (enlace firmado que se envía por WhatsApp). */
    public function publico(Cotizacion $cotizacion)
    {
        return $this->cotizaciones->pdf($cotizacion)->inline("{$cotizacion->codigo()}.pdf");
    }

    /** Enlace firmado de 30 días al PDF de la cotización. */
    public static function enlacePublico(Cotizacion $cotizacion): string
    {
        return URL::temporarySignedRoute('cotizaciones.publico', now()->addDays(30), ['cotizacion' => $cotizacion->id]);
    }

    /** Manda la cotización en PDF al correo indicado; opcionalmente lo guarda en la ficha del cliente. */
    public function correo(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_unless($cotizacion->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'guardar_en_cliente' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Ingresa el correo del cliente.',
            'email.email' => 'El correo no es válido.',
        ]);

        if ($request->boolean('guardar_en_cliente') && $cotizacion->cliente && blank($cotizacion->cliente->email)) {
            $cotizacion->cliente->update(['email' => $datos['email']]);
        }

        // el PDF y el SMTP tardan: se hace despues de responder
        EnviarCotizacionPorCorreo::dispatchAfterResponse($cotizacion->id, $datos['email']);

        return back()->with('success', "La cotización {$cotizacion->codigo()} se está enviando a {$datos['email']}.");
    }

    private function guardar(Request $request, ?Cotizacion $cotizacion): RedirectResponse
    {
        $usuario = $request->user();

        $datos = $request->validate([
            'cliente_id' => ['nullable', 'uuid'],
            'fecha_emision' => ['required', 'date'],
            'valida_hasta' => ['required', 'date', 'after_or_equal:fecha_emision'],
            'tiempo_entrega' => ['nullable', 'string', 'max:100'],
            'direccion_envio' => ['nullable', 'string', 'max:250'],
            'es_credito' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'fecha_emision.required' => 'Indica la fecha de la cotización.',
            'valida_hasta.required' => 'Indica hasta cuándo es válida la cotización.',
            'valida_hasta.after_or_equal' => 'La validez no puede ser anterior a la fecha de emisión.',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.cantidad.gt' => 'La cantidad debe ser mayor a 0.',
        ]);

        // cotizar a un precio distinto al de lista exige el mismo permiso que en el POS
        if (! $usuario->can('pos.precio_manual')) {
            $datos['items'] = array_map(fn ($i) => [...$i, 'precio_unitario' => null], $datos['items']);
        }

        $sucursalId = $cotizacion?->sucursal_id ?? $this->sucursalDeTrabajo($request);

        if (! $sucursalId) {
            return back()->with('error', 'No tienes una sucursal asignada.');
        }

        try {
            $guardada = $this->cotizaciones->guardar($usuario, $sucursalId, $datos, $cotizacion);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo guardar la cotización. Intenta de nuevo.');
        }

        $total = number_format((float) $guardada->total, 2);

        return redirect()->route('cotizaciones.index')
            ->with('success', $cotizacion
                ? "Cotización {$guardada->codigo()} actualizada (S/ {$total})."
                : "Cotización {$guardada->codigo()} creada por S/ {$total}.");
    }

    /** Formulario de crear/editar: catálogo con precios y, si aplica, la cotización a cargar. */
    private function formulario(Request $request, ?Cotizacion $cotizacion, ?Cotizacion $origen): Response
    {
        $empresa = $request->user()->empresa;
        $afectos = TipoAfectacionIgv::where('afecto', true)->pluck('codigo')->map(fn ($c) => trim($c))->all();

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'permite_fraccion', 'tipo_afectacion_codigo', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        $base = $cotizacion ?? $origen;

        return Inertia::render('Cotizaciones/Formulario', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'permite_fraccion' => $p->permite_fraccion,
                'imagen_url' => $p->imagen_url,
                // gravado | exonerado | inafecto (el Nuevo RUS cotiza todo como exonerado)
                'afectacion' => in_array(trim((string) $p->tipo_afectacion_codigo), $afectos, true)
                    ? ($empresa->esRus() ? 'exonerado' : 'gravado')
                    : (trim((string) $p->tipo_afectacion_codigo) === '20' ? 'exonerado' : 'inafecto'),
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'precio_venta' => (float) $pres->precio_venta,
                    'precio_mayorista' => $pres->precio_mayorista !== null ? (float) $pres->precio_mayorista : null,
                    'cantidad_mayorista' => $pres->cantidad_mayorista !== null ? (float) $pres->cantidad_mayorista : null,
                    'factor_conversion' => (float) $pres->factor_conversion,
                    'codigo_barras' => $pres->codigo_barras,
                    'es_default' => $pres->es_default,
                ]),
            ]),
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre']),
            'validezDias' => self::VALIDEZ_DIAS,
            // al editar se conserva todo; al duplicar solo cliente, condiciones y productos (fechas nuevas)
            'cotizacion' => $cotizacion ? ['id' => $cotizacion->id, 'codigo' => $cotizacion->codigo()] : null,
            'inicial' => $base ? [
                'fecha_emision' => $cotizacion?->fecha_emision->toDateString(),
                'valida_hasta' => $cotizacion?->valida_hasta->toDateString(),
                'tiempo_entrega' => $base->tiempo_entrega,
                'direccion_envio' => $base->direccion_envio,
                'es_credito' => $base->es_credito,
                'observaciones' => $base->observaciones,
                'cliente' => $base->cliente && ! $base->cliente->trashed() ? [
                    'id' => $base->cliente->id,
                    'nombre' => $base->cliente->nombre,
                    'tipo_documento_codigo' => $base->cliente->tipo_documento_codigo,
                    'numero_documento' => $base->cliente->numero_documento,
                    'direccion' => $base->cliente->direccion,
                ] : null,
                'items' => $base->detalles->map(fn ($d) => [
                    'presentacion_id' => $d->presentacion_id,
                    'cantidad' => (float) $d->cantidad,
                    'precio_unitario' => (float) $d->precio_unitario,
                    'descuento' => (float) $d->descuento,
                ]),
            ] : null,
        ]);
    }
}
