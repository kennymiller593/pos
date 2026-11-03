<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Models\MedioPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TipoAfectacionIgv;
use App\Models\TipoDocumentoIdentidad;
use App\Services\CajaService;
use App\Services\PedidoService;
use App\Support\ContextoSucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PedidoController extends Controller
{
    public const PESTANAS = ['atrasados', 'hoy', 'manana', 'proximos', 'historial'];

    public function __construct(private readonly PedidoService $pedidos, private readonly CajaService $caja) {}

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'pestana' => ['nullable', Rule::in(self::PESTANAS)],
            'buscar' => ['nullable', 'string', 'max:100'],
        ]);

        $usuario = $request->user();
        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        $pestana = $filtros['pestana'] ?? 'hoy';
        $hoy = now()->toDateString();
        $manana = now()->addDay()->toDateString();

        $base = Pedido::query()
            ->where('empresa_id', $usuario->empresa_id)
            // la sucursal elegida en el selector (null = todas las que puede ver)
            ->when($this->sucursalesConsulta($request), fn ($q, $ids) => $q->whereIn('sucursal_id', $ids))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cliente_nombre', 'ilike', "%{$buscar}%")
                ->orWhere('cliente_numero_doc', 'ilike', "{$buscar}%")
                ->orWhere('cliente_telefono', 'ilike', "%{$buscar}%")
                ->when(preg_match('/(\d+)$/', $buscar, $m), fn ($q) => $q->orWhere('numero', (int) $m[1]))));

        // cuántos hay en cada pestaña (con el mismo buscador) para los contadores
        $conteos = (clone $base)->selectRaw(
            "COUNT(*) FILTER (WHERE estado IN ('pendiente','listo') AND fecha_entrega < ?) AS atrasados,
             COUNT(*) FILTER (WHERE estado IN ('pendiente','listo') AND fecha_entrega = ?) AS hoy,
             COUNT(*) FILTER (WHERE estado IN ('pendiente','listo') AND fecha_entrega = ?) AS manana,
             COUNT(*) FILTER (WHERE estado IN ('pendiente','listo') AND fecha_entrega > ?) AS proximos,
             COUNT(*) FILTER (WHERE estado IN ('entregado','anulado')) AS historial",
            [$hoy, $hoy, $manana, $manana],
        )->first();

        $pedidos = (clone $base)
            ->with([
                'sucursal:id,nombre',
                'detalles:id,pedido_id,descripcion,unidad_codigo,cantidad,precio_unitario,descuento,total,orden',
                'usuario:id,nombre_completo',
                'cliente' => fn ($q) => $q->withTrashed()->select('id', 'telefono'),
                'adelantos.usuario:id,nombre_completo',
                'adelantos.medioPago:codigo,nombre',
                'comprobante:id,serie,correlativo,tipo_comprobante_codigo',
            ])
            ->when($pestana === 'historial',
                fn ($q) => $q->whereIn('estado', ['entregado', 'anulado'])->orderByDesc('actualizado_en'),
                fn ($q) => $q->abiertos()
                    ->when($pestana === 'atrasados', fn ($q) => $q->where('fecha_entrega', '<', $hoy))
                    ->when($pestana === 'hoy', fn ($q) => $q->where('fecha_entrega', $hoy))
                    ->when($pestana === 'manana', fn ($q) => $q->where('fecha_entrega', $manana))
                    ->when($pestana === 'proximos', fn ($q) => $q->where('fecha_entrega', '>', $manana))
                    ->orderBy('fecha_entrega')->orderByRaw('hora_entrega ASC NULLS LAST')->orderBy('numero'))
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Pedido $p) => $this->fila($p));

        $empresa = $usuario->empresa;
        $apertura = $this->caja->aperturaDe($usuario);

        return Inertia::render('Pedidos/Index', [
            'pedidos' => $pedidos,
            'filtros' => ['pestana' => $pestana, 'buscar' => $buscar],
            'conteos' => [
                'atrasados' => (int) $conteos->atrasados,
                'hoy' => (int) $conteos->hoy,
                'manana' => (int) $conteos->manana,
                'proximos' => (int) $conteos->proximos,
                'historial' => (int) $conteos->historial,
            ],
            'empresaNombre' => $empresa->nombre_comercial ?: $empresa->razon_social,
            'mediosPago' => MedioPago::elegibles()->orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            'cajaAbierta' => $apertura !== null,
            // el dinero de un pedido entra y sale por una caja de su sucursal: la pantalla apaga "Adelanto" en las otras
            'cajaSucursalId' => $apertura?->caja?->sucursal_id,
        ]);
    }

    public function crear(Request $request): Response
    {
        return $this->formulario($request, null);
    }

    public function editar(Request $request, Pedido $pedido): Response|RedirectResponse
    {
        $this->autorizar($request, $pedido);

        if ($pedido->estado !== 'pendiente') {
            return redirect()->route('pedidos.index')->with('error', 'Solo se puede editar un pedido pendiente.');
        }

        return $this->formulario($request, $pedido->load(['detalles', 'cliente']));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->guardar($request, null);
    }

    public function update(Request $request, Pedido $pedido): RedirectResponse
    {
        $this->autorizar($request, $pedido);

        return $this->guardar($request, $pedido);
    }

    public function adelanto(Request $request, Pedido $pedido): RedirectResponse
    {
        $this->autorizar($request, $pedido);

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0'],
            'medio_pago_codigo' => ['required', Rule::exists('medios_pago', 'codigo')->whereNotIn('codigo', MedioPago::INTERNOS)],
            'referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'monto.required' => 'Ingresa el monto del adelanto.',
            'monto.gt' => 'El adelanto debe ser mayor a 0.',
            'medio_pago_codigo.required' => 'Elige cómo pagó el cliente.',
            'medio_pago_codigo.exists' => 'Elige un medio de pago válido.',
        ]);

        try {
            $adelanto = $this->pedidos->registrarAdelanto($pedido, $request->user(), $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $saldo = number_format($pedido->fresh()->saldo(), 2);

        return back()->with('success', sprintf('Adelanto de S/ %s registrado en caja. Saldo del pedido %s: S/ %s.', number_format((float) $adelanto->monto, 2), $pedido->codigo(), $saldo));
    }

    public function listo(Request $request, Pedido $pedido): RedirectResponse
    {
        $this->autorizar($request, $pedido);

        try {
            $this->pedidos->marcarListo($pedido, $request->user());
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pedido {$pedido->codigo()} marcado como listo.");
    }

    public function anular(Request $request, Pedido $pedido): RedirectResponse
    {
        $this->autorizar($request, $pedido);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:250'],
            'devolver_adelanto' => ['required', 'boolean'],
            'medio_devolucion' => ['nullable', Rule::exists('medios_pago', 'codigo')->whereNotIn('codigo', MedioPago::INTERNOS)],
        ], [
            'motivo.required' => 'Indica por qué se anula el pedido.',
        ]);

        $devolver = $request->boolean('devolver_adelanto');
        $adelantos = $pedido->totalAdelantos();

        try {
            $this->pedidos->anular($pedido, $request->user(), $datos['motivo'], $devolver, $datos['medio_devolucion'] ?? null);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        $mensaje = "Pedido {$pedido->codigo()} anulado.";
        if ($adelantos > 0) {
            $mensaje .= $devolver
                ? ' Se devolvieron S/ '.number_format($adelantos, 2).' de caja.'
                : ' El adelanto de S/ '.number_format($adelantos, 2).' queda como no devuelto.';
        }

        return back()->with('success', $mensaje);
    }

    /** "Orden de pedido" en formato térmico: lo que hay que alistar, los adelantos y el saldo. */
    public function ticket(Request $request, Pedido $pedido)
    {
        $this->autorizar($request, $pedido);

        $ancho = (int) ($this->caja->aperturaDe($request->user())?->caja?->ancho_ticket ?? 80);

        return $this->pedidos->ticket($pedido, $request->user()->empresa, $ancho, html: $request->query('formato') === 'html');
    }

    private function guardar(Request $request, ?Pedido $pedido): RedirectResponse
    {
        $usuario = $request->user();

        $datos = $request->validate([
            'cliente_id' => ['required', 'uuid'],
            // al editar, la entrega no puede ser anterior al día en que se pidió
            'fecha_entrega' => ['required', 'date', 'after_or_equal:'.($pedido ? $pedido->fecha_pedido->toDateString() : 'today')],
            'hora_entrega' => ['nullable', 'date_format:H:i'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'cliente_id.required' => 'Elige el cliente que recoge el pedido.',
            'fecha_entrega.required' => 'Indica la fecha de entrega.',
            'fecha_entrega.after_or_equal' => 'La entrega no puede ser una fecha pasada.',
            'hora_entrega.date_format' => 'La hora no es válida.',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.cantidad.gt' => 'La cantidad debe ser mayor a 0.',
        ]);

        // pedir a un precio distinto al de lista exige el mismo permiso que en el POS; al editar sin ese permiso
        // se conservan los precios ya fijados (solo lo nuevo va a precio de lista)
        if (! $usuario->can('pos.precio_manual')) {
            $fijados = $pedido ? $pedido->detalles()->pluck('precio_unitario', 'presentacion_id')->all() : [];
            $datos['items'] = array_map(fn ($i) => [...$i, 'precio_unitario' => isset($fijados[$i['presentacion_id']]) ? (float) $fijados[$i['presentacion_id']] : null], $datos['items']);
        }

        $sucursalId = $pedido?->sucursal_id ?? $this->sucursalDelPedido($request);

        if (! $sucursalId) {
            return back()->with('error', 'No tienes una sucursal asignada.');
        }

        try {
            $guardado = $this->pedidos->guardar($usuario, $sucursalId, $datos, $pedido);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo guardar el pedido. Intenta de nuevo.');
        }

        $total = number_format((float) $guardado->total, 2);
        $entrega = $guardado->fecha_entrega->format('d/m');

        return redirect()->route('pedidos.index', ['pestana' => $this->pestanaDe($guardado)])
            ->with('success', $pedido
                ? "Pedido {$guardado->codigo()} actualizado (S/ {$total}, entrega el {$entrega})."
                : "Pedido {$guardado->codigo()} registrado por S/ {$total} para el {$entrega}.");
    }

    /** El pedido es de la empresa y de una sucursal a la que el usuario puede entrar. */
    private function autorizar(Request $request, Pedido $pedido): void
    {
        abort_unless($pedido->empresa_id === $request->user()->empresa_id, 403);

        $permitidas = ContextoSucursal::de($request)->permitidas;
        abort_if($permitidas !== null && ! in_array($pedido->sucursal_id, $permitidas, true), 403);
    }

    /**
     * Sede de un pedido nuevo: la elegida en el selector; si está en "todas", la de su caja abierta
     * (así puede recibir el adelanto de inmediato) y, si no tiene caja, su sucursal por defecto.
     */
    private function sucursalDelPedido(Request $request): ?string
    {
        $contexto = ContextoSucursal::de($request);

        return $contexto->trabajoId() ?? $contexto->cajaAbierta['sucursal_id'] ?? $contexto->porDefectoId($request);
    }

    /** Pestaña donde cae el pedido por su fecha de entrega, para volver a la lista mostrándolo. */
    private function pestanaDe(Pedido $pedido): string
    {
        $dias = now()->startOfDay()->diffInDays($pedido->fecha_entrega, false);

        return match (true) {
            $dias < 0 => 'atrasados',
            $dias == 0 => 'hoy',
            $dias == 1 => 'manana',
            default => 'proximos',
        };
    }

    /** Fila de la lista: el pedido con sus productos y adelantos. */
    private function fila(Pedido $p): array
    {
        $adelantos = round((float) $p->adelantos->sum('monto'), 2);

        return [
            'id' => $p->id,
            'codigo' => $p->codigo(),
            'estado' => $p->estado,
            'atrasado' => $p->estaAtrasado(),
            'fecha_pedido' => $p->fecha_pedido->toDateString(),
            'fecha_entrega' => $p->fecha_entrega->toDateString(),
            'hora_entrega' => $p->horaEntrega(),
            'cliente_nombre' => $p->cliente_nombre,
            'cliente_numero_doc' => $p->cliente_numero_doc,
            // el celular actual del cliente manda; si lo borró, queda la copia del pedido
            'cliente_telefono' => $p->cliente?->telefono ?: $p->cliente_telefono,
            'observaciones' => $p->observaciones,
            'total' => (float) $p->total,
            'total_adelantos' => $adelantos,
            'saldo' => round(max(0, (float) $p->total - $adelantos), 2),
            'vendedor' => $p->usuario?->nombre_completo,
            'sucursal' => $p->sucursal?->nombre,
            'sucursal_id' => $p->sucursal_id,
            'comprobante' => $p->comprobante
                ? "{$p->comprobante->serie}-".str_pad((string) $p->comprobante->correlativo, 6, '0', STR_PAD_LEFT)
                : null,
            'motivo_anulacion' => $p->motivo_anulacion,
            'devolucion_monto' => $p->devolucion_monto !== null ? (float) $p->devolucion_monto : null,
            'adelantos' => $p->adelantos->map(fn ($a) => [
                'id' => $a->id,
                'fecha' => $a->creado_en,
                'monto' => (float) $a->monto,
                'medio' => $a->medioPago?->nombre ?? $a->medio_pago_codigo,
                'medio_codigo' => $a->medio_pago_codigo,
                'referencia' => $a->referencia,
                'usuario' => $a->usuario?->nombre_completo,
            ]),
            'detalles' => $p->detalles->map(fn ($d) => [
                'id' => $d->id,
                'descripcion' => $d->descripcion,
                'unidad_codigo' => trim((string) $d->unidad_codigo),
                'cantidad' => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'descuento' => (float) $d->descuento,
                'total' => (float) $d->total,
            ]),
        ];
    }

    /** Formulario de crear/editar: catálogo con precios y stock de referencia y, si aplica, el pedido a cargar. */
    private function formulario(Request $request, ?Pedido $pedido): Response
    {
        $empresa = $request->user()->empresa;
        $afectos = TipoAfectacionIgv::where('afecto', true)->pluck('codigo')->map(fn ($c) => trim($c))->all();
        $sucursalId = $pedido?->sucursal_id ?? $this->sucursalDelPedido($request);

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            // stock en la sucursal del pedido: solo como referencia, el pedido no lo reserva
            ->withSum(['stock as stock' => fn ($q) => $q->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))], 'cantidad')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'permite_fraccion', 'controla_stock', 'tipo_afectacion_codigo', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        return Inertia::render('Pedidos/Formulario', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'permite_fraccion' => $p->permite_fraccion,
                'controla_stock' => $p->controla_stock,
                'stock' => (float) ($p->stock ?? 0),
                'imagen_url' => $p->imagen_url,
                // gravado | exonerado | inafecto (el Nuevo RUS vende todo como exonerado)
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
            // sede en la que queda el pedido (sus adelantos y su entrega se hacen desde una caja de esa sede)
            'sucursalNombre' => ContextoSucursal::de($request)->nombreDe($sucursalId),
            'pedido' => $pedido ? [
                'id' => $pedido->id,
                'codigo' => $pedido->codigo(),
                'fecha_pedido' => $pedido->fecha_pedido->toDateString(),
                'total_adelantos' => $pedido->totalAdelantos(),
            ] : null,
            'inicial' => $pedido ? [
                'fecha_entrega' => $pedido->fecha_entrega->toDateString(),
                'hora_entrega' => $pedido->horaEntrega(),
                'observaciones' => $pedido->observaciones,
                'cliente' => $pedido->cliente && ! $pedido->cliente->trashed() ? [
                    'id' => $pedido->cliente->id,
                    'nombre' => $pedido->cliente->nombre,
                    'tipo_documento_codigo' => $pedido->cliente->tipo_documento_codigo,
                    'numero_documento' => $pedido->cliente->numero_documento,
                    'direccion' => $pedido->cliente->direccion,
                    'telefono' => $pedido->cliente->telefono,
                ] : null,
                'items' => $pedido->detalles->map(fn ($d) => [
                    'presentacion_id' => $d->presentacion_id,
                    'cantidad' => (float) $d->cantidad,
                    'precio_unitario' => (float) $d->precio_unitario,
                    'descuento' => (float) $d->descuento,
                ]),
            ] : null,
        ]);
    }
}
