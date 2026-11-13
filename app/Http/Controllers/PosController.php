<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarComprobanteSunat;
use App\Models\AperturaCaja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\CuentaPorCobrar;
use App\Models\MedioPago;
use App\Models\MovimientoPuntos;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ProductoSerie;
use App\Models\TipoDocumentoIdentidad;
use App\Services\CajaService;
use App\Services\CobroQrService;
use App\Services\CotizacionService;
use App\Services\PedidoService;
use App\Services\PuntosService;
use App\Services\SerieService;
use App\Services\VarianteService;
use App\Services\VentaService;
use App\Support\ContextoSucursal;
use App\Support\DocumentoIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function __construct(
        private readonly CajaService $caja,
        private readonly VentaService $ventas,
        private readonly CotizacionService $cotizaciones,
        private readonly PedidoService $pedidos,
        private readonly PuntosService $puntos,
    ) {}

    public function index(Request $request): Response
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return Inertia::render('Pos/Index', ['apertura' => null]);
        }

        $sucursalId = $apertura->caja->sucursal_id;
        $empresaId = $request->user()->empresa_id;

        $productos = Producto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->withSum(['stock as stock' => fn ($q) => $q->where('sucursal_id', $sucursalId)], 'cantidad')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'categoria_id', 'controla_stock', 'controla_serie', 'permite_fraccion', 'imagen_url', 'grupo_id', 'atributos', 'variante_orden'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        // variantes (tallas y colores): los modelos de esos productos, para agruparlos en una tarjeta con su rejilla
        $modelos = VarianteService::modelosDe($request->user()->empresa, $productos);

        // productos con serie / IMEI: lo vendible son las unidades con serie disponibles en esta sucursal (una unidad
        // en stock sin número no se puede vender). Una sola consulta agrupada, y solo si hay productos con serie
        $conSerie = $productos->where('controla_serie', true)->pluck('id');
        $vendibles = $conSerie->isEmpty() ? collect() : ProductoSerie::query()
            ->whereIn('producto_id', $conSerie)
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'disponible')
            ->selectRaw('producto_id, count(*) as total')
            ->groupBy('producto_id')
            ->toBase()->pluck('total', 'producto_id');

        return Inertia::render('Pos/Index', [
            'apertura' => ['id' => $apertura->id, 'caja' => $apertura->caja->nombre],
            'facturacionElectronica' => (bool) $request->user()->empresa->facturacion_electronica,
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'categoria_id' => $p->categoria_id,
                'controla_stock' => $p->controla_stock,
                // se vende eligiendo la unidad (serie / IMEI); las unidades se piden aparte a /pos/series
                'controla_serie' => (bool) $p->controla_serie,
                'permite_fraccion' => $p->permite_fraccion,
                'imagen_url' => $p->imagen_url,
                'stock' => $p->controla_serie ? (float) min((float) ($p->stock ?? 0), (int) ($vendibles[$p->id] ?? 0)) : (float) ($p->stock ?? 0),
                // solo en variantes de un modelo: grupo_id, grupo, atributos y variante_orden
                ...VarianteService::camposDeVariante($p, $modelos),
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
            'categorias' => Categoria::where('empresa_id', $empresaId)->orderBy('nombre')->get(['id', 'nombre']),
            'mediosPago' => MedioPago::elegibles()->orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            // QR de Yape/Plin para mostrar al cliente: el de la sucursal de la caja o, si no tiene, el de la empresa
            'cobrosQr' => app(CobroQrService::class)->de($request->user()->empresa, $apertura->caja->sucursal),
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre']),
            'cotizacion' => $request->filled('pedido') ? null : $this->cotizacionParaCarrito($request),
            // /pos?pedido={id}: el pedido que se entrega (productos, precios fijados, cliente y adelanto)
            'pedido' => $this->pedidoParaCarrito($request, $apertura),
            // reglas del programa de puntos, o null si la empresa no lo usa
            'puntos' => $this->puntos->reglas($request->user()->empresa),
            // solo si hay variantes a la venta: [{ id, nombre, atributos: [{ nombre, valores }] }]
            ...($modelos->isNotEmpty() ? ['modelos' => VarianteService::modelosParaPantalla($modelos)] : []),
        ]);
    }

    /**
     * /pos?pedido={id}: el pedido abierto se carga en el carrito para entregarlo. Se respetan los precios
     * fijados al pedir y el adelanto ya recibido se descuenta de lo que hay que cobrar.
     */
    private function pedidoParaCarrito(Request $request, AperturaCaja $apertura): ?array
    {
        if (! $request->filled('pedido')) {
            return null;
        }

        $pedido = $this->pedidos->paraVender($request->user()->empresa_id, $request->query('pedido'));
        $permitidas = ContextoSucursal::de($request)->permitidas;

        if (! $pedido || ($permitidas !== null && ! in_array($pedido->sucursal_id, $permitidas, true))) {
            $request->session()->flash('error', 'Ese pedido ya no está abierto o no es de tu sucursal.');

            return null;
        }

        // el pedido se cobra desde una caja de su sede: no se carga un carrito que luego no se podrá cobrar
        if ($apertura->caja->sucursal_id !== $pedido->sucursal_id) {
            $nombre = $pedido->sucursal?->nombre ?? 'otra sucursal';
            $request->session()->flash('error', "El pedido {$pedido->codigo()} es de la sucursal {$nombre}: para entregarlo abre una caja de esa sucursal.");

            return null;
        }

        $cliente = $pedido->cliente;

        return [
            'id' => $pedido->id,
            'codigo' => $pedido->codigo(),
            'estado' => $pedido->estado,
            'fecha_entrega' => $pedido->fecha_entrega->toDateString(),
            'hora_entrega' => $pedido->horaEntrega(),
            'total' => (float) $pedido->total,
            'adelanto' => $pedido->totalAdelantos(),
            'saldo' => $pedido->saldo(),
            'sucursal_id' => $pedido->sucursal_id,
            'cliente' => $cliente && ! $cliente->trashed() ? [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $cliente->direccion,
                'email' => $cliente->email,
                'telefono' => $cliente->telefono,
                'limite_credito' => (float) $cliente->limite_credito,
                'deuda' => (float) CuentaPorCobrar::where('cliente_id', $cliente->id)->where('estado', '!=', 'pagado')->sum(DB::raw('monto_total - monto_pagado')),
            ] : null,
            'items' => $pedido->detalles->map(fn ($d) => [
                'presentacion_id' => $d->presentacion_id,
                'descripcion' => $d->descripcion,
                'cantidad' => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'descuento' => (float) $d->descuento,
            ]),
        ];
    }

    /**
     * /pos?cotizacion={id}: la cotización aceptada por el cliente se carga en el carrito.
     * Mientras siga vigente se respetan sus precios; vencida, entran los precios de lista de hoy.
     */
    private function cotizacionParaCarrito(Request $request): ?array
    {
        $cotizacion = $this->cotizaciones->paraVender($request->user()->empresa_id, $request->query('cotizacion'));

        if (! $cotizacion) {
            return null;
        }

        $cliente = $cotizacion->cliente;

        return [
            'id' => $cotizacion->id,
            'codigo' => $cotizacion->codigo(),
            'vigente' => ! $cotizacion->estaVencida(),
            'cliente' => $cliente ? [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $cliente->direccion,
                'email' => $cliente->email,
                'limite_credito' => (float) $cliente->limite_credito,
                'deuda' => (float) CuentaPorCobrar::where('cliente_id', $cliente->id)->where('estado', '!=', 'pagado')->sum(DB::raw('monto_total - monto_pagado')),
            ] : null,
            'items' => $cotizacion->detalles->map(fn ($d) => [
                'presentacion_id' => $d->presentacion_id,
                'descripcion' => $d->descripcion,
                'cantidad' => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
                'descuento' => (float) $d->descuento,
            ]),
        ];
    }

    public function clientes(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar'));
        // con id el POS refresca el cliente que tenía guardado (su límite y deuda pueden haber cambiado)
        $id = trim((string) $request->query('id'));

        if ($buscar === '' && $id === '') {
            return response()->json([]);
        }

        return response()->json(
            Cliente::query()
                ->where('empresa_id', $request->user()->empresa_id)
                ->addSelect([
                    'id', 'nombre', 'tipo_documento_codigo', 'numero_documento', 'direccion', 'email', 'telefono', 'limite_credito', 'puntos',
                    'deuda' => CuentaPorCobrar::query()
                        ->selectRaw('COALESCE(SUM(monto_total - monto_pagado), 0)')
                        ->whereColumn('cliente_id', 'clientes.id')
                        ->where('estado', '!=', 'pagado'),
                ])
                ->when($id !== '', fn ($q) => $q->whereKey($id))
                ->when($id === '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('nombre', 'ilike', "%{$buscar}%")
                    ->orWhere('numero_documento', 'ilike', "{$buscar}%")))
                ->orderBy('nombre')
                ->limit(10)
                ->get()
        );
    }

    /**
     * Unidades con serie / IMEI para el POS, siempre de la sucursal de la caja abierta:
     *  - ?producto_id=...[&buscar=...]: las disponibles de ese producto, para elegir en el modal.
     *  - ?numero=...: la unidad de una serie escaneada o escrita (serie exacta o segundo IMEI), con su producto.
     */
    public function series(Request $request, SerieService $series): JsonResponse
    {
        // parámetros de texto simples: un ?numero[]=x responde 422 en vez de romper
        $request->validate([
            'numero' => ['nullable', 'string', 'max:'.SerieService::MAX_LARGO],
            'producto_id' => ['nullable', 'uuid'],
            'buscar' => ['nullable', 'string', 'max:'.SerieService::MAX_LARGO],
        ]);

        $usuario = $request->user();
        $apertura = $this->caja->aperturaDe($usuario);

        if (! $apertura) {
            return response()->json(['series' => [], 'encontradas' => [], 'aviso' => 'Necesitas abrir caja antes de vender.']);
        }

        $sucursalId = $apertura->caja->sucursal_id;
        $forma = fn (ProductoSerie $s) => ['id' => $s->id, 'producto_id' => $s->producto_id, 'numero_serie' => $s->numero_serie, 'serie_alterna' => $s->serie_alterna];

        $numero = trim((string) $request->query('numero'));
        if ($numero !== '') {
            $halladas = $series->buscar($usuario->empresa_id, mb_substr($numero, 0, SerieService::MAX_LARGO));
            $vendibles = $halladas->filter(fn (ProductoSerie $s) => $s->estado === 'disponible' && $s->sucursal_id === $sucursalId)->values();

            $aviso = null;
            if ($vendibles->isEmpty() && ($otra = $halladas->first())) {
                $aviso = match (true) {
                    $otra->estado === 'vendida' => "La serie {$otra->numero_serie} ya está vendida.",
                    $otra->estado === 'baja' => "La serie {$otra->numero_serie} está dada de baja.",
                    $otra->estado === 'en_transito' => "La serie {$otra->numero_serie} está en tránsito entre sucursales.",
                    default => "La serie {$otra->numero_serie} está en otra sucursal (".($otra->sucursal?->nombre ?? 'otra').').',
                };
            }

            // el nombre del producto de cada una: si la misma serie existe en dos productos, el POS deja elegir
            $nombres = Producto::withTrashed()->whereIn('id', $vendibles->pluck('producto_id'))->pluck('nombre', 'id');

            return response()->json([
                'encontradas' => $vendibles->map(fn (ProductoSerie $s) => [...$forma($s), 'producto' => $nombres[$s->producto_id] ?? null]),
                'aviso' => $aviso,
                'existe' => $halladas->isNotEmpty(),
            ]);
        }

        $productoId = (string) $request->query('producto_id');
        $producto = Str::isUuid($productoId)
            ? Producto::where('empresa_id', $usuario->empresa_id)->find($productoId)
            : null;

        if (! $producto) {
            return response()->json(['series' => []]);
        }

        return response()->json([
            'series' => ($lista = $series->disponibles($producto->id, $sucursalId, (string) $request->query('buscar')))->map($forma),
            'series_truncadas' => $lista->count() >= SerieService::LIMITE_LISTA,
        ]);
    }

    /** Creación rápida de cliente desde el POS (responde JSON). */
    public function crearCliente(Request $request): JsonResponse
    {
        $empresaId = $request->user()->empresa_id;

        $datos = $request->validate([
            'tipo_documento_codigo' => ['required', Rule::exists('tipos_documento_identidad', 'codigo')],
            'numero_documento' => [
                'nullable', 'string', 'max:15',
                DocumentoIdentidad::regla($request->input('tipo_documento_codigo')),
                Rule::unique('clientes', 'numero_documento')
                    ->where('empresa_id', $empresaId)
                    ->where('tipo_documento_codigo', $request->input('tipo_documento_codigo'))
                    ->whereNull('eliminado_en'),
            ],
            'nombre' => ['required', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'limite_credito' => ['nullable', 'numeric', 'min:0'],
        ], [
            'nombre.required' => 'Ingresa el nombre.',
            'numero_documento.unique' => 'Ya tienes un cliente con este documento.',
        ]);

        $cliente = Cliente::create([
            ...$datos,
            'limite_credito' => $datos['limite_credito'] ?? 0,
            'empresa_id' => $empresaId,
        ]);

        return response()->json([
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
            'numero_documento' => $cliente->numero_documento,
            'direccion' => $cliente->direccion,
            'limite_credito' => (float) $cliente->limite_credito,
            'deuda' => 0,
            'puntos' => 0,
        ], 201);
    }

    /** Lo que la venta movio en puntos, para avisarle al cliente: null si no movio nada. */
    private function puntosDeLaVenta(Comprobante $comprobante): ?array
    {
        $movimientos = MovimientoPuntos::where('comprobante_id', $comprobante->id)->get(['tipo', 'puntos', 'cliente_id']);

        if ($movimientos->isEmpty()) {
            return null;
        }

        return [
            'ganados' => (int) $movimientos->where('tipo', 'ganado')->sum('puntos'),
            'canjeados' => -(int) $movimientos->where('tipo', 'canje')->sum('puntos'),
            'saldo' => (int) Cliente::whereKey($movimientos->first()->cliente_id)->value('puntos'),
        ];
    }

    public function vender(Request $request): RedirectResponse
    {
        $apertura = $this->caja->aperturaDe($request->user());

        if (! $apertura) {
            return back()->with('error', 'Necesitas abrir caja antes de vender.');
        }

        $esCredito = $request->boolean('es_credito');

        // sin facturacion electronica activa solo se emiten notas de venta internas
        // y el Nuevo RUS no emite facturas
        $empresa = $request->user()->empresa;
        $tiposPermitidos = ! $empresa->facturacion_electronica ? 'in:00' : ($empresa->esRus() ? 'in:00,03' : 'in:00,03,01');

        // entrega de un pedido: los pagos pueden ir vacíos (el adelanto puede cubrir todo); el servicio
        // comprueba que pagos + adelanto cuadren con el total
        $entregaPedido = $request->filled('pedido_id');

        $datos = $request->validate([
            'tipo_comprobante_codigo' => ['required', $tiposPermitidos],
            'cliente_id' => [$esCredito ? 'required' : 'nullable', 'uuid'],
            'cotizacion_id' => ['nullable', 'uuid'],
            'pedido_id' => ['nullable', 'uuid'],
            'puntos_canjeados' => ['nullable', 'integer', 'min:0'],
            'es_credito' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'gt:0'],
            // productos que controlan serie / IMEI: el id de cada unidad que se entrega
            'items.*.series' => ['nullable', 'array', 'max:'.SerieService::MAX_POR_LINEA_VENTA],
            'items.*.series.*' => ['required', 'uuid'],
            'pagos' => [$esCredito || $entregaPedido ? 'nullable' : 'required', 'array'],
            'pagos.*.medio_pago_codigo' => ['required', Rule::exists('medios_pago', 'codigo')->whereNotIn('codigo', MedioPago::INTERNOS)],
            'pagos.*.monto' => ['required', 'numeric', 'gt:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:100'],
            // "paga ahora, a cuenta": abono que el cliente deja al fiar (solo con es_credito)
            'abono' => ['nullable', 'array'],
            'abono.medio_pago_codigo' => ['required_with:abono', Rule::exists('medios_pago', 'codigo')->whereNotIn('codigo', MedioPago::INTERNOS)],
            'abono.monto' => ['required_with:abono', 'numeric', 'gt:0'],
            'abono.referencia' => ['nullable', 'string', 'max:100'],
            'clave' => ['nullable', 'uuid'],
            'canal_venta' => ['nullable', Rule::in(['tienda', 'online'])],
        ], [
            'items.required' => 'El carrito está vacío.',
            'items.*.series.max' => 'Máximo '.SerieService::MAX_POR_LINEA_VENTA.' equipos con serie por línea; agrega otra línea o haz otra venta.',
            'pagos.required' => 'Indica cómo se pagó la venta.',
            'cliente_id.required' => 'La venta al crédito necesita un cliente.',
            'abono.monto.required_with' => 'El monto a cuenta debe ser mayor a 0.',
            'abono.monto.numeric' => 'El monto a cuenta debe ser mayor a 0.',
            'abono.monto.gt' => 'El monto a cuenta debe ser mayor a 0.',
            'abono.medio_pago_codigo.required_with' => 'Elige un medio de pago válido para el abono.',
            'abono.medio_pago_codigo.exists' => 'Elige un medio de pago válido para el abono.',
        ]);

        // la misma venta enviada dos veces (doble clic, recarga, respuesta que no llegó) se registra una sola vez:
        // el POS manda una clave por carrito y con ella se devuelve la venta ya hecha en vez de repetirla
        $claveCache = filled($datos['clave'] ?? null) ? "pos.venta.{$request->user()->id}.{$datos['clave']}" : null;
        $firma = md5(json_encode([$datos['tipo_comprobante_codigo'], $datos['cliente_id'] ?? null, $esCredito, $datos['items'], $datos['pagos'] ?? [], $datos['abono'] ?? null, $datos['pedido_id'] ?? null]));
        if ($claveCache && ($previa = Cache::get($claveCache)) && $previa['firma'] === $firma) {
            $registrada = Comprobante::whereKey($previa['id'])->where('empresa_id', $empresa->id)->first();
            if ($registrada) {
                return $this->respuestaVenta($registrada, $esCredito, yaRegistrada: true);
            }
        }

        $pedido = $entregaPedido ? $this->pedidos->paraVender($empresa->id, $datos['pedido_id']) : null;
        if ($entregaPedido && ! $pedido) {
            return back()->with('error', 'Este pedido ya fue entregado o anulado (quizá por otro usuario). Revisa la lista de pedidos antes de volver a cobrar.');
        }

        // venta de una cotizacion vigente o entrega de un pedido: sus precios se respetan aunque el rol no pueda cambiar precios
        $cotizacion = $pedido ? null : $this->cotizaciones->paraVender($empresa->id, $datos['cotizacion_id'] ?? null);
        $cotizados = $this->cotizaciones->preciosVigentes($cotizacion) + $this->pedidos->preciosFijados($pedido);

        if ($pedido) {
            try {
                $this->pedidos->validarEntrega($pedido, $apertura, $esCredito);
            } catch (ErrorDeNegocio $e) {
                return back()->with('error', $e->getMessage());
            }
            // el adelanto lo pone el servidor desde el pedido, nunca el navegador; y la venta va al cliente del pedido
            $datos['adelanto'] = ['monto' => $pedido->totalAdelantos(), 'referencia' => $pedido->codigo()];
            if ($pedido->cliente && ! $pedido->cliente->trashed()) {
                $datos['cliente_id'] = $pedido->cliente_id;
            }
        }

        $conPrecioManual = collect($datos['items'])->contains(fn ($i) => filled($i['precio_unitario'] ?? null)
            && abs((float) $i['precio_unitario'] - ($cotizados[$i['presentacion_id']] ?? -1)) >= 0.005);
        if ($conPrecioManual && ! $request->user()->can('pos.precio_manual')) {
            return back()->with('error', 'Tu rol no puede cambiar el precio de lista. Pide a un administrador o cajero que lo haga.');
        }

        try {
            // la venta y el "entregado" del pedido van juntos y con el pedido bloqueado: dos cajas con el mismo
            // pedido cargado no lo entregan dos veces (la segunda ve que ya no está abierto)
            $comprobante = DB::transaction(function () use ($request, $apertura, $datos, $pedido) {
                if ($pedido && ! Pedido::whereKey($pedido->id)->abiertos()->lockForUpdate()->exists()) {
                    throw new ErrorDeNegocio('Este pedido ya fue entregado o anulado por otro usuario: no se registró la venta.');
                }

                $comprobante = $this->ventas->registrar($request->user(), $apertura, $datos);

                if ($pedido) {
                    $this->pedidos->marcarEntregado($pedido, $comprobante);
                }

                return $comprobante;
            });
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la venta. Intenta de nuevo.');
        }

        if ($claveCache) {
            Cache::put($claveCache, ['id' => $comprobante->id, 'firma' => $firma], now()->addHours(2));
        }

        if ($cotizacion) {
            $this->cotizaciones->marcarConvertida($cotizacion, $comprobante);
        }

        // el envio a SUNAT corre despues de responder para no demorar la caja
        if (in_array($comprobante->tipo_comprobante_codigo, ['01', '03'], true)) {
            EnviarComprobanteSunat::dispatchAfterResponse($comprobante->id);
        }

        return $this->respuestaVenta($comprobante, $esCredito, pedido: $pedido);
    }

    /** Vuelve al POS con el aviso y los datos de la venta para el modal de venta exitosa. */
    private function respuestaVenta(Comprobante $comprobante, bool $esCredito, bool $yaRegistrada = false, ?Pedido $pedido = null): RedirectResponse
    {
        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
        $total = number_format((float) $comprobante->total, 2);

        $comprobante->loadMissing(['cliente:id,email,telefono', 'empresa:id,razon_social,nombre_comercial,facturacion_electronica']);

        // venta fiada: lo que el cliente dejo a cuenta en el momento y lo que le queda por pagar
        $abono = null;
        $saldo = null;
        if ($esCredito) {
            $comprobante->loadMissing('cuentaPorCobrar.cobros.medioPago');
            $abono = $comprobante->cuentaPorCobrar?->abonoInicial();
            $saldo = round((float) $comprobante->total - (float) ($abono?->monto ?? 0), 2);
        }

        $mensaje = match (true) {
            $yaRegistrada => "La venta {$numero} por S/ {$total} ya estaba registrada; no se repitió.",
            $pedido !== null => "Pedido {$pedido->codigo()} entregado con {$numero} por S/ {$total}: adelanto S/ ".number_format($pedido->totalAdelantos(), 2).', cobrado ahora S/ '.number_format(max(0, (float) $comprobante->total - $pedido->totalAdelantos()), 2).'.',
            $esCredito && $abono => "Venta {$numero} al crédito por S/ {$total} registrada a \"{$comprobante->cliente_nombre}\": a cuenta S/ ".number_format((float) $abono->monto, 2).', saldo S/ '.number_format($saldo, 2).'.',
            $esCredito => "Venta {$numero} al crédito por S/ {$total} registrada a \"{$comprobante->cliente_nombre}\".",
            default => "Venta {$numero} registrada por S/ {$total}.",
        };

        return back()
            ->with('ticket', route('comprobantes.ticket', $comprobante))
            // datos para el modal de venta exitosa: vistas previas, estado SUNAT y envio al cliente
            ->with('venta', [
                'id' => $comprobante->id,
                'numero' => $numero,
                'tipo' => ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta'][$comprobante->tipo_comprobante_codigo] ?? 'Comprobante',
                'electronico' => in_array($comprobante->tipo_comprobante_codigo, ['01', '03'], true),
                'envio_automatico' => (bool) $comprobante->empresa->facturacion_electronica,
                'total' => (float) $comprobante->total,
                'empresa' => $comprobante->empresa->nombre_comercial ?: $comprobante->empresa->razon_social,
                'cliente_email' => $comprobante->cliente?->email,
                'cliente_telefono' => $comprobante->cliente?->telefono,
                'enlace_publico' => ComprobanteController::enlacePublico($comprobante),
                'puntos' => $this->puntosDeLaVenta($comprobante),
                // venta fiada: abono dejado a cuenta (0 si no hubo) y saldo pendiente (null si fue al contado)
                'abono' => (float) ($abono?->monto ?? 0),
                'abono_medio' => $abono?->medioPago?->nombre,
                'saldo' => $saldo,
                // entrega de un pedido: lo que ya estaba adelantado (el POS limpia el pedido cargado)
                'pedido' => $pedido ? ['id' => $pedido->id, 'codigo' => $pedido->codigo(), 'adelanto' => $pedido->totalAdelantos()] : null,
            ])
            ->with('success', $mensaje);
    }
}
