<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarGuiaSunat;
use App\Models\Comprobante;
use App\Models\GuiaRemision;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\TipoDocumentoIdentidad;
use App\Models\Transferencia;
use App\Models\Ubigeo;
use App\Services\GuiaRemisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GuiaRemisionController extends Controller
{
    public function __construct(private readonly GuiaRemisionService $guias) {}

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['pendiente', 'en_proceso', 'aceptado', 'rechazado', 'anulada'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        $guias = GuiaRemision::query()
            ->where('empresa_id', $request->user()->empresa_id)
            ->with([
                'detalles:id,guia_id,codigo,descripcion,unidad_codigo,cantidad,orden',
                'usuario:id,nombre_completo',
                'sucursal:id,nombre',
                'comprobante:id,serie,correlativo',
            ])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('destinatario_nombre', 'ilike', "%{$buscar}%")
                ->orWhere('destinatario_numero_doc', 'ilike', "{$buscar}%")
                ->orWhere('vehiculo_placa', 'ilike', "{$buscar}%")
                ->when(preg_match('/(\d+)$/', $buscar, $m), fn ($q) => $q->orWhere('correlativo', (int) $m[1]))))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => match ($estado) {
                'anulada' => $q->where('estado', 'anulada'),
                'aceptado' => $q->where('estado', 'emitida')->whereIn('estado_sunat', ['aceptado', 'observado']),
                default => $q->where('estado', 'emitida')->where('estado_sunat', $estado),
            })
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->where('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha_emision', '<=', $hasta))
            ->orderByDesc('creado_en')
            ->paginate(10)
            ->withQueryString();

        $ubigeos = Ubigeo::whereIn('codigo', $guias->getCollection()->flatMap(fn ($g) => [$g->partida_ubigeo, $g->llegada_ubigeo])->unique())
            ->get()
            ->keyBy(fn ($u) => trim($u->codigo));
        $lugar = fn (string $codigo) => ($u = $ubigeos->get(trim($codigo)))
            ? mb_convert_case("{$u->distrito}, {$u->provincia}", MB_CASE_TITLE)
            : $codigo;

        $empresa = $request->user()->empresa;

        return Inertia::render('Guias/Index', [
            'guias' => $guias->through(fn (GuiaRemision $g) => [
                'id' => $g->id,
                'numero' => $g->numero(),
                'fecha_emision' => $g->fecha_emision->toDateString(),
                'fecha_traslado' => $g->fecha_traslado->toDateString(),
                'motivo' => GuiaRemision::MOTIVOS[trim($g->motivo_codigo)] ?? 'Otros',
                'motivo_descripcion' => $g->motivo_descripcion,
                'modalidad' => trim($g->modalidad),
                'destinatario_nombre' => $g->destinatario_nombre,
                'destinatario_numero_doc' => $g->destinatario_numero_doc,
                'partida' => "{$g->partida_direccion} · {$lugar($g->partida_ubigeo)}",
                'llegada' => "{$g->llegada_direccion} · {$lugar($g->llegada_ubigeo)}",
                'peso_bruto' => (float) $g->peso_bruto,
                'bultos' => $g->bultos,
                'transportista' => $g->transportista_nombre ? "{$g->transportista_nombre} · RUC {$g->transportista_ruc}" : null,
                'vehiculo_placa' => $g->vehiculo_placa,
                'vehiculo_menor' => $g->vehiculo_menor,
                'conductor' => $g->conductor_numero_doc
                    ? trim("{$g->conductor_nombres} {$g->conductor_apellidos}")." · {$g->conductor_numero_doc} · Lic. {$g->conductor_licencia}"
                    : null,
                'comprobante' => $g->comprobante ? "{$g->comprobante->serie}-".str_pad((string) $g->comprobante->correlativo, 6, '0', STR_PAD_LEFT) : null,
                'observaciones' => $g->observaciones,
                'estado' => $g->estado,
                'estado_sunat' => $g->estado_sunat,
                'sunat_mensaje' => $g->sunat_mensaje,
                'motivo_anulacion' => $g->motivo_anulacion,
                'tiene_xml' => filled($g->xml_url),
                'tiene_cdr' => filled($g->cdr_url),
                'usuario' => $g->usuario?->nombre_completo,
                'sucursal' => $g->sucursal?->nombre,
                'detalles' => $g->detalles->map(fn ($d) => [
                    'id' => $d->id,
                    'codigo' => $d->codigo,
                    'descripcion' => $d->descripcion,
                    'unidad_codigo' => trim((string) $d->unidad_codigo),
                    'cantidad' => (float) $d->cantidad,
                ]),
            ]),
            'filtros' => [
                'buscar' => $buscar,
                'estado' => $filtros['estado'] ?? '',
                'desde' => $filtros['desde'] ?? '',
                'hasta' => $filtros['hasta'] ?? '',
            ],
            'envioSunat' => $this->estadoEnvio($empresa),
        ]);
    }

    public function crear(Request $request): Response
    {
        $usuario = $request->user();
        $empresa = $usuario->empresa;

        $inicial = $this->inicialDesdeOrigen($request);

        // la guia de una transferencia sale del local de origen de esa transferencia
        $partidaId = $inicial['sucursal_partida_id'] ?? $this->sucursalDeTrabajo($request);
        $partida = Sucursal::where('empresa_id', $empresa->id)->find($partidaId);

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->with(['presentaciones' => fn ($q) => $q->where('activo', true)->orderByDesc('es_default')->orderBy('nombre')])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_interno', 'permite_fraccion', 'imagen_url'])
            ->filter(fn ($p) => $p->presentaciones->isNotEmpty())
            ->values();

        return Inertia::render('Guias/Formulario', [
            'productos' => $productos->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_interno' => $p->codigo_interno,
                'permite_fraccion' => $p->permite_fraccion,
                'imagen_url' => $p->imagen_url,
                'presentaciones' => $p->presentaciones->map(fn ($pres) => [
                    'id' => $pres->id,
                    'nombre' => $pres->nombre,
                    'factor_conversion' => (float) $pres->factor_conversion,
                    'codigo_barras' => $pres->codigo_barras,
                    'es_default' => $pres->es_default,
                ]),
            ]),
            'partida' => $partida ? [
                'id' => $partida->id,
                'nombre' => $partida->nombre,
                'direccion' => $partida->direccion,
                'lugar' => $this->lugar($partida->ubigeo),
                'completa' => filled($partida->direccion) && filled(trim((string) $partida->ubigeo)),
            ] : null,
            'sucursales' => Sucursal::where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')
                ->get(['id', 'nombre', 'direccion', 'ubigeo'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'direccion' => $s->direccion,
                    'lugar' => $this->lugar($s->ubigeo),
                    'completa' => filled($s->direccion) && filled(trim((string) $s->ubigeo)),
                ]),
            'tiposDocumento' => TipoDocumentoIdentidad::orderBy('codigo')->get(['codigo', 'nombre']),
            // como lista: un objeto JSON reordenaria la clave numerica "13" antes que "01"
            'motivos' => collect(GuiaRemision::MOTIVOS)->map(fn ($nombre, $codigo) => ['codigo' => str_pad((string) $codigo, 2, '0', STR_PAD_LEFT), 'nombre' => $nombre])->values(),
            'sugerencias' => $this->sugerenciasDeTransporte($empresa->id),
            'inicial' => $inicial,
            'envioSunat' => $this->estadoEnvio($empresa),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $empresa = $usuario->empresa;

        // la placa y la licencia se comparan sin guiones ni espacios
        $request->merge([
            'vehiculo_placa' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('vehiculo_placa'))) ?: null,
            'conductor_licencia' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('conductor_licencia'))) ?: null,
        ]);

        $entreLocales = $request->input('motivo_codigo') === '04';
        $publico = $request->input('modalidad') === '01';
        // privado en vehiculo M1/L: SUNAT no exige placa ni conductor
        $exigeVehiculo = ! $publico && ! $request->boolean('vehiculo_menor');

        $datos = $request->validate([
            'motivo_codigo' => ['required', Rule::in(array_keys(GuiaRemision::MOTIVOS))],
            'motivo_descripcion' => ['nullable', 'required_if:motivo_codigo,13', 'string', 'max:100'],
            'fecha_traslado' => ['required', 'date', 'after_or_equal:today'],
            'peso_bruto' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'bultos' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'cliente_id' => [$entreLocales ? 'nullable' : 'required', 'uuid'],
            'llegada_ubigeo' => [$entreLocales ? 'nullable' : 'required', 'string', 'size:6'],
            'llegada_direccion' => [$entreLocales ? 'nullable' : 'required', 'string', 'max:250'],
            'sucursal_destino_id' => [$entreLocales ? 'required' : 'nullable', 'uuid'],
            'modalidad' => ['required', Rule::in(array_keys(GuiaRemision::MODALIDADES))],
            'transportista_ruc' => [$publico ? 'required' : 'nullable', 'regex:/^(10|15|17|20)\d{9}$/', Rule::notIn([$empresa->ruc])],
            'transportista_nombre' => [$publico ? 'required' : 'nullable', 'string', 'max:200'],
            'transportista_mtc' => ['nullable', 'string', 'max:20'],
            'vehiculo_menor' => ['nullable', 'boolean'],
            'vehiculo_placa' => [$exigeVehiculo ? 'required' : 'nullable', 'regex:/^[A-Z0-9]{6,8}$/'],
            'conductor_tipo_doc' => ['nullable', Rule::in(['1', '4', '7'])],
            'conductor_numero_doc' => [$exigeVehiculo ? 'required' : 'nullable', 'string', 'max:15',
                $request->input('conductor_tipo_doc', '1') === '1' ? 'regex:/^\d{8}$/' : 'regex:/^[A-Za-z0-9]{6,15}$/'],
            'conductor_nombres' => ['nullable', 'required_with:conductor_numero_doc', 'string', 'max:100'],
            'conductor_apellidos' => ['nullable', 'required_with:conductor_numero_doc', 'string', 'max:100'],
            'conductor_licencia' => ['nullable', 'required_with:conductor_numero_doc', 'regex:/^[A-Z0-9]{9,10}$/'],
            'observaciones' => ['nullable', 'string', 'max:250'],
            'comprobante_id' => ['nullable', 'uuid'],
            'transferencia_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.presentacion_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ], [
            'motivo_descripcion.required_if' => 'Describe el motivo del traslado.',
            'fecha_traslado.required' => 'Indica cuándo inicia el traslado.',
            'fecha_traslado.after_or_equal' => 'El traslado no puede iniciar antes de hoy.',
            'peso_bruto.required' => 'Indica el peso bruto total en kilos.',
            'peso_bruto.gt' => 'El peso debe ser mayor a 0.',
            'cliente_id.required' => 'Elige el destinatario.',
            'llegada_ubigeo.required' => 'Elige el distrito del punto de llegada.',
            'llegada_direccion.required' => 'Escribe la dirección del punto de llegada.',
            'sucursal_destino_id.required' => 'Elige la sucursal de destino.',
            'transportista_ruc.required' => 'Ingresa el RUC de la empresa de transportes.',
            'transportista_ruc.regex' => 'El RUC del transportista debe tener 11 dígitos.',
            'transportista_ruc.not_in' => 'El transportista no puede ser tu propia empresa: si llevas la mercadería tú, elige transporte privado.',
            'transportista_nombre.required' => 'Ingresa la razón social del transportista.',
            'vehiculo_placa.required' => 'Ingresa la placa del vehículo.',
            'vehiculo_placa.regex' => 'La placa debe tener de 6 a 8 letras o números (ej. ABC123).',
            'conductor_numero_doc.required' => 'Ingresa el documento del conductor.',
            'conductor_numero_doc.regex' => 'El documento del conductor no es válido.',
            'conductor_nombres.required_with' => 'Ingresa los nombres del conductor.',
            'conductor_apellidos.required_with' => 'Ingresa los apellidos del conductor.',
            'conductor_licencia.required_with' => 'Ingresa la licencia de conducir.',
            'conductor_licencia.regex' => 'La licencia debe tener 9 o 10 letras o números (ej. Q12345678).',
            'items.required' => 'Agrega al menos un producto.',
            'items.*.cantidad.gt' => 'La cantidad debe ser mayor a 0.',
        ]);

        // el punto de partida es la sucursal de trabajo; en una transferencia, su local de origen
        $sucursalId = $this->sucursalDeTrabajo($request);
        if ($entreLocales && filled($datos['transferencia_id'] ?? null)) {
            $sucursalId = Transferencia::where('empresa_id', $empresa->id)->whereKey($datos['transferencia_id'])->value('sucursal_origen_id') ?? $sucursalId;
        }

        $sucursal = Sucursal::where('empresa_id', $empresa->id)->find($sucursalId);
        if (! $sucursal) {
            return back()->with('error', 'No tienes una sucursal asignada.');
        }

        try {
            $guia = $this->guias->crear($usuario, $sucursal, $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo registrar la guía. Intenta de nuevo.');
        }

        // el envio a SUNAT corre despues de responder
        if ($empresa->facturacion_electronica) {
            EnviarGuiaSunat::dispatchAfterResponse($guia->id);
        }

        return redirect()->route('guias.index')->with('success', $empresa->facturacion_electronica
            ? "Guía {$guia->numero()} registrada. Se está enviando a SUNAT."
            : "Guía {$guia->numero()} registrada. Activa la facturación electrónica en Empresa para enviarla a SUNAT.");
    }

    /** Reenvía una guía pendiente o consulta el ticket de una en proceso. */
    public function enviarSunat(Request $request, GuiaRemision $guia): RedirectResponse
    {
        abort_unless($guia->empresa_id === $request->user()->empresa_id, 403);

        if ($guia->estado !== 'emitida') {
            return back()->with('error', 'La guía está anulada.');
        }

        $guia = $guia->estado_sunat === 'en_proceso' ? $this->guias->consultar($guia) : $this->guias->enviar($guia);

        return match ($guia->estado_sunat) {
            'aceptado', 'observado' => back()->with('success', "SUNAT aceptó la guía {$guia->numero()}."),
            'en_proceso' => back()->with('success', "SUNAT recibió la guía {$guia->numero()} y aún la procesa. Vuelve a consultar en unos segundos."),
            'rechazado' => back()->with('error', "SUNAT rechazó la guía {$guia->numero()}: {$guia->sunat_mensaje}"),
            default => back()->with('error', "No se pudo enviar la guía {$guia->numero()}: {$guia->sunat_mensaje}"),
        };
    }

    public function anular(Request $request, GuiaRemision $guia): RedirectResponse
    {
        abort_unless($guia->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:250']], ['motivo.required' => 'Indica el motivo de la anulación.']);

        try {
            $this->guias->anular($guia, $request->user(), $datos['motivo']);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', in_array($guia->estado_sunat, ['aceptado', 'observado'], true)
            ? "Guía {$guia->numero()} anulada en el sistema. Como SUNAT ya la había aceptado, dala de baja también en SUNAT Operaciones en Línea."
            : "Guía {$guia->numero()} anulada.");
    }

    public function pdf(Request $request, GuiaRemision $guia)
    {
        abort_unless($guia->empresa_id === $request->user()->empresa_id, 403);

        return $this->guias->pdf($guia)->inline("{$guia->numero()}.pdf");
    }

    /** Descarga el XML firmado que se envió a SUNAT. */
    public function xml(Request $request, GuiaRemision $guia)
    {
        return $this->descargar($request, $guia, 'xml_url');
    }

    /** Descarga el CDR (constancia de recepción) que devolvió SUNAT. */
    public function cdr(Request $request, GuiaRemision $guia)
    {
        return $this->descargar($request, $guia, 'cdr_url');
    }

    private function descargar(Request $request, GuiaRemision $guia, string $campo)
    {
        abort_unless($guia->empresa_id === $request->user()->empresa_id, 403);

        $ruta = $guia->{$campo};
        abort_unless($ruta && Storage::exists($ruta), 404);

        return Storage::download($ruta, basename($ruta));
    }

    /** Qué le falta a la empresa para que sus guías lleguen a SUNAT (se muestra como aviso). */
    private function estadoEnvio($empresa): array
    {
        $faltaCredenciales = $empresa->entorno_sunat === 'produccion'
            && (blank($empresa->gre_client_id) || blank($empresa->gre_client_secret));

        return [
            'activo' => (bool) $empresa->facturacion_electronica,
            'falta_credenciales' => (bool) $empresa->facturacion_electronica && $faltaCredenciales,
            'entorno' => $empresa->entorno_sunat,
        ];
    }

    private function lugar(?string $ubigeo): ?string
    {
        $u = filled(trim((string) $ubigeo)) ? Ubigeo::find(trim($ubigeo)) : null;

        return $u ? mb_convert_case("{$u->distrito}, {$u->provincia}, {$u->departamento}", MB_CASE_TITLE) : null;
    }

    /** Vehículos, conductores y transportistas usados antes, para no volver a escribirlos. */
    private function sugerenciasDeTransporte(string $empresaId): array
    {
        $recientes = GuiaRemision::query()
            ->where('empresa_id', $empresaId)
            ->orderByDesc('creado_en')
            ->limit(200)
            ->get(['vehiculo_placa', 'conductor_tipo_doc', 'conductor_numero_doc', 'conductor_nombres', 'conductor_apellidos',
                'conductor_licencia', 'transportista_ruc', 'transportista_nombre', 'transportista_mtc']);

        return [
            'vehiculos' => $recientes->pluck('vehiculo_placa')->filter()->unique()->values()->take(15),
            'conductores' => $recientes->filter(fn ($g) => filled($g->conductor_numero_doc))
                ->unique('conductor_numero_doc')->values()->take(15)
                ->map(fn ($g) => [
                    'tipo_doc' => trim((string) $g->conductor_tipo_doc) ?: '1',
                    'numero_doc' => $g->conductor_numero_doc,
                    'nombres' => $g->conductor_nombres,
                    'apellidos' => $g->conductor_apellidos,
                    'licencia' => $g->conductor_licencia,
                ]),
            'transportistas' => $recientes->filter(fn ($g) => filled($g->transportista_ruc))
                ->unique('transportista_ruc')->values()->take(15)
                ->map(fn ($g) => [
                    'ruc' => trim((string) $g->transportista_ruc),
                    'nombre' => $g->transportista_nombre,
                    'mtc' => $g->transportista_mtc,
                ]),
        ];
    }

    /**
     * "Generar guía" desde una venta (?comprobante=) o una transferencia (?transferencia=):
     * el formulario arranca con el destinatario y los productos ya cargados.
     */
    private function inicialDesdeOrigen(Request $request): ?array
    {
        $empresaId = $request->user()->empresa_id;

        if ($request->filled('comprobante')) {
            $venta = Comprobante::where('empresa_id', $empresaId)
                ->with(['detalles:id,comprobante_id,presentacion_id,cantidad', 'cliente'])
                ->find($request->query('comprobante'));

            if (! $venta || $venta->estado !== 'emitido' || ! in_array($venta->tipo_comprobante_codigo, ['00', '01', '03'], true)) {
                return null;
            }

            $cliente = $venta->cliente;

            return [
                'origen' => 'Venta '.$venta->serie.'-'.str_pad((string) $venta->correlativo, 6, '0', STR_PAD_LEFT),
                'motivo_codigo' => '01',
                'comprobante_id' => $venta->id,
                'cliente' => $cliente ? [
                    'id' => $cliente->id,
                    'nombre' => $cliente->nombre,
                    'tipo_documento_codigo' => $cliente->tipo_documento_codigo,
                    'numero_documento' => $cliente->numero_documento,
                    'direccion' => $cliente->direccion,
                ] : null,
                'llegada_direccion' => $cliente?->direccion,
                'items' => $venta->detalles->filter(fn ($d) => $d->presentacion_id)->values()
                    ->map(fn ($d) => ['presentacion_id' => $d->presentacion_id, 'cantidad' => (float) $d->cantidad]),
            ];
        }

        if ($request->filled('transferencia')) {
            $transferencia = Transferencia::where('empresa_id', $empresaId)
                ->with(['detalles:id,transferencia_id,producto_id,cantidad', 'detalles.producto.presentaciones'])
                ->find($request->query('transferencia'));

            if (! $transferencia || $transferencia->estado === 'anulada') {
                return null;
            }

            return [
                'origen' => 'Transferencia del '.$transferencia->creado_en->format('d/m/Y'),
                'motivo_codigo' => '04',
                'transferencia_id' => $transferencia->id,
                'sucursal_partida_id' => $transferencia->sucursal_origen_id,
                'sucursal_destino_id' => $transferencia->sucursal_destino_id,
                // la transferencia guarda unidades base: se pasan a la presentacion suelta del producto
                'items' => $transferencia->detalles
                    ->groupBy('producto_id')
                    ->map(function ($lineas) {
                        $presentaciones = $lineas->first()->producto?->presentaciones?->where('activo', true) ?? collect();
                        $presentacion = $presentaciones->first(fn ($p) => (float) $p->factor_conversion == 1.0)
                            ?? $presentaciones->firstWhere('es_default', true)
                            ?? $presentaciones->first();

                        if (! $presentacion) {
                            return null;
                        }

                        $factor = (float) $presentacion->factor_conversion ?: 1;

                        return ['presentacion_id' => $presentacion->id, 'cantidad' => round((float) $lineas->sum('cantidad') / $factor, 3)];
                    })
                    ->filter()
                    ->values(),
            ];
        }

        return null;
    }
}
