<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarComprobantePorCorreo;
use App\Jobs\EnviarComprobanteSunat;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\MedioPago;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Services\ComprobantePdfService;
use App\Services\NotaCreditoService;
use App\Services\SunatService;
use App\Services\VentaService;
use App\Support\ExportadorExcel;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ComprobanteController extends Controller
{
    public function __construct(
        private readonly VentaService $ventas,
        private readonly ComprobantePdfService $pdf,
    ) {}

    public function index(Request $request): Response
    {
        [$consulta, $filtros, $orden, $dir] = $this->consulta($request);

        $comprobantes = $consulta
            ->with([
                'usuario:id,nombre_completo',
                'cliente:id,email',
                'detalles:id,comprobante_id,descripcion,unidad_codigo,cantidad,precio_unitario,total',
                'pagos:id,comprobante_id,medio_pago_codigo,monto,referencia',
                'pagos.medioPago:codigo,nombre',
                'sunat:comprobante_id,estado,mensaje_sunat,intentos,enviado_en,xml_url,cdr_url,ticket',
                'notas' => fn ($q) => $q
                    ->select('id', 'comprobante_ref_id', 'serie', 'correlativo', 'motivo_nota', 'total', 'estado', 'tipo_comprobante_codigo', 'creado_en')
                    ->where('estado', 'emitido')
                    ->orderBy('creado_en')
                    ->with('sunat:comprobante_id,estado,mensaje_sunat'),
            ])
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Comprobantes/Index', [
            'comprobantes' => $comprobantes,
            'filtros' => $filtros,
            'orden' => ['columna' => $orden, 'dir' => $dir],
            'mediosPago' => MedioPago::orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
            // nombre de cada unidad para el detalle (KGM -> Kilogramo)
            'unidades' => UnidadMedida::pluck('nombre', 'codigo'),
        ]);
    }

    /** La lista con sus filtros y orden (la comparten la pantalla y la exportacion). */
    private function consulta(Request $request): array
    {
        $filtros = $request->only(['buscar', 'tipo', 'estado', 'sunat']);
        // rango de fechas de emision (AAAA-MM-DD); una fecha mal escrita se ignora
        foreach (['desde', 'hasta'] as $campo) {
            $valor = (string) $request->query($campo);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && strtotime($valor)) {
                $filtros[$campo] = $valor;
            }
        }

        // orden por columna (?orden=total&dir=asc); por defecto, lo mas reciente primero
        $orden = in_array($request->query('orden'), array_keys(self::ORDENES), true) ? $request->query('orden') : 'fecha';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $consulta = Comprobante::query()
            ->where('empresa_id', $request->user()->empresa_id)
            // las notas de credito no se listan sueltas: viajan dentro de su comprobante original
            ->where('tipo_comprobante_codigo', '!=', '07')
            ->when($filtros['buscar'] ?? null, function ($q, $buscar) {
                $q->where(function ($w) use ($buscar) {
                    $w->where('serie', 'ilike', "%{$buscar}%")
                        ->orWhere('cliente_nombre', 'ilike', "%{$buscar}%")
                        ->orWhere('cliente_numero_doc', 'ilike', "{$buscar}%");
                    if (is_numeric($buscar)) {
                        $w->orWhere('correlativo', (int) $buscar);
                    }
                });
            })
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->where('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha_emision', '<=', $hasta))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo_comprobante_codigo', $tipo))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            // "pendiente" agrupa lo que SUNAT aun no acepto: sin envio, pendiente o rechazado
            ->when($filtros['sunat'] ?? null, fn ($q, $sunat) => $sunat === 'pendiente'
                ? $q->whereIn('tipo_comprobante_codigo', ['01', '03'])
                    ->where(fn ($w) => $w->whereDoesntHave('sunat')->orWhereHas('sunat', fn ($s) => $s->whereIn('estado', ['pendiente', 'rechazado'])))
                : $q->whereHas('sunat', fn ($s) => $s->where('estado', $sunat)))
            ->tap(fn ($q) => $this->ordenar($q, $orden, $dir));

        return [$consulta, $filtros, $orden, $dir];
    }

    /** Excel con los comprobantes tal como se ven en la lista (mismos filtros y orden), sin paginar. */
    public function exportar(Request $request)
    {
        [$consulta, $filtros] = $this->consulta($request);

        $tipos = ['00' => 'Nota de venta', '01' => 'Factura', '03' => 'Boleta'];
        $sunat = ['aceptado' => 'Aceptado', 'observado' => 'Observado', 'rechazado' => 'Rechazado', 'pendiente' => 'Pendiente', 'baja_pendiente' => 'Baja en proceso', 'baja' => 'Dado de baja'];
        $total = 0.0;
        $cantidad = 0;

        $filas = $consulta
            ->with(['usuario:id,nombre_completo', 'sunat:comprobante_id,estado'])
            ->lazy(500)
            ->map(function (Comprobante $c) use ($tipos, $sunat, &$total, &$cantidad) {
                if ($c->estado === 'emitido') {
                    $total += (float) $c->total;
                    $cantidad++;
                }

                return [
                    "{$c->serie}-".str_pad((string) $c->correlativo, 6, '0', STR_PAD_LEFT),
                    $tipos[$c->tipo_comprobante_codigo] ?? $c->tipo_comprobante_codigo,
                    $c->fecha_emision->format('d/m/Y'),
                    substr((string) $c->hora_emision, 0, 5),
                    $c->cliente_nombre ?? 'Público general',
                    $c->cliente_numero_doc,
                    (float) $c->total_gravado,
                    (float) $c->total_exonerado,
                    (float) $c->total_inafecto,
                    (float) $c->total_igv,
                    (float) $c->total_descuentos,
                    (float) $c->total,
                    $c->es_credito ? 'Crédito' : 'Contado',
                    $c->estado === 'emitido' ? 'Emitido' : 'Anulado',
                    in_array($c->tipo_comprobante_codigo, ['01', '03'], true) ? ($sunat[$c->sunat?->estado ?? 'pendiente'] ?? 'Pendiente') : 'No aplica',
                    $c->usuario?->nombre_completo,
                ];
            });

        // lazy(): las filas se generan al escribir, y el resumen recien queda completo al final
        $filas = $filas->all();

        $periodo = ($filtros['desde'] ?? null) || ($filtros['hasta'] ?? null)
            ? 'Del '.($filtros['desde'] ?? 'inicio').' al '.($filtros['hasta'] ?? 'hoy')
            : 'Todos los comprobantes';

        return (new ExportadorExcel)
            ->hoja(
                'Comprobantes',
                'Comprobantes',
                $periodo.' · '.now()->format('d/m/Y H:i'),
                ['Número', 'Tipo', 'Fecha', 'Hora', 'Cliente', 'Documento', 'Gravado', 'Exonerado', 'Inafecto', 'IGV', 'Descuentos', 'Total', 'Condición', 'Estado', 'SUNAT', 'Vendedor'],
                $filas,
                [
                    ['etiqueta' => 'Comprobantes emitidos', 'valor' => $cantidad],
                    ['etiqueta' => 'Total emitido', 'valor' => 'S/ '.number_format($total, 2)],
                ],
            )
            ->descargar('comprobantes-'.now()->format('Ymd-Hi'));
    }

    /** Columnas por las que se puede ordenar la lista. */
    private const ORDENES = [
        'numero' => null, 'tipo' => 'tipo_comprobante_codigo', 'fecha' => null, 'cliente' => 'cliente_nombre',
        'total' => 'total', 'estado' => 'estado', 'sunat' => null, 'vendedor' => null,
    ];

    private function ordenar($query, string $orden, string $dir): void
    {
        match ($orden) {
            'numero' => $query->orderBy('serie', $dir)->orderBy('correlativo', $dir),
            'fecha' => $query->orderBy('fecha_emision', $dir)->orderBy('hora_emision', $dir),
            'sunat' => $query->orderBy(ComprobanteSunat::select('estado')->whereColumn('comprobante_id', 'comprobantes.id'), $dir),
            'vendedor' => $query->orderBy(Usuario::select('nombre_completo')->whereColumn('id', 'comprobantes.usuario_id'), $dir),
            // "Publico general" (sin cliente) va al final al ordenar por nombre
            'cliente' => $query->orderByRaw("cliente_nombre IS NULL, cliente_nombre {$dir}"),
            default => $query->orderBy(self::ORDENES[$orden], $dir),
        };

        $query->orderByDesc('creado_en'); // desempate estable
    }

    /** Ticket imprimible en formato térmico de 80mm. */
    public function ticket(Request $request, Comprobante $comprobante)
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $comprobante->load([
            'detalles:id,comprobante_id,descripcion,cantidad,precio_unitario,total',
            'pagos.medioPago:codigo,nombre',
            'usuario:id,nombre_completo',
            'sucursal:id,nombre,direccion',
            'caja:id,ancho_ticket',
            'comprobanteRef:id,serie,correlativo,tipo_comprobante_codigo',
        ]);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
        $empresa = $request->user()->empresa;
        $logo = $empresa->logoParaPdf();
        $ancho = (int) ($comprobante->caja?->ancho_ticket ?? 80);
        $qr = $this->pdf->qr($comprobante, $empresa);

        // impresion directa: la misma vista como HTML que se imprime solo desde el
        // navegador (con Chrome en --kiosk-printing sale a la termica sin dialogo)
        if ($request->query('formato') === 'html') {
            return view('pdf.ticket', [
                'comprobante' => $comprobante,
                'empresa' => $empresa,
                'numero' => $numero,
                'logo' => $logo,
                'ancho' => $ancho,
                'qr' => $qr,
                'hash' => $comprobante->hash_cpe,
                'imprimirDirecto' => true,
            ]);
        }

        // alto del papel segun el contenido, calibrado contra renders reales
        // (la termica corta al final; 58mm ocupa mas lineas por fila)
        $alto = (int) ceil(
            ($ancho === 58 ? 45 : 52)
            + $comprobante->detalles->count() * ($ancho === 58 ? 6.5 : 5)
            + $comprobante->pagos->count() * 3.5
            + ($comprobante->cliente_nombre ? 7 : 0)
            + ($comprobante->es_credito ? 6 : 0)
            + ($comprobante->estado === 'anulado' ? 10 : 0)
            + ($comprobante->comprobanteRef ? 4 : 0)
            + ($logo ? 18 : 0)
            + ($qr ? 30 : 0)
        );

        return SnappyPdf::loadView('pdf.ticket', [
            'comprobante' => $comprobante,
            'empresa' => $empresa,
            'numero' => $numero,
            'logo' => $logo,
            'ancho' => $ancho,
            'qr' => $qr,
            'hash' => $comprobante->hash_cpe,
        ])
            ->setOption('page-width', "{$ancho}mm")
            ->setOption('page-height', "{$alto}mm")
            ->setOption('margin-top', '3')
            ->setOption('margin-bottom', '3')
            ->setOption('margin-left', '4')
            ->setOption('margin-right', '4')
            ->setOption('encoding', 'utf-8')
            ->inline("ticket-{$numero}.pdf");
    }

    /** Versión A4 del comprobante, para enviar por correo o imprimir en papel normal. */
    public function a4(Request $request, Comprobante $comprobante)
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return $this->pdf->a4($comprobante)->inline("{$numero}.pdf");
    }

    /** Versión A5 (media hoja): la plantilla A4 reducida. */
    public function a5(Request $request, Comprobante $comprobante)
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return $this->pdf->a4($comprobante, 'A5')->inline("{$numero}-A5.pdf");
    }

    /** Estado SUNAT del comprobante, para el modal de venta exitosa (se consulta cada pocos segundos). */
    public function estadoSunat(Request $request, Comprobante $comprobante): JsonResponse
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $registro = $comprobante->sunat;

        return response()->json([
            'electronico' => in_array($comprobante->tipo_comprobante_codigo, ['01', '03', '07'], true),
            'estado' => $registro?->estado, // null = aun no se envia
            'mensaje' => $registro?->mensaje_sunat,
        ]);
    }

    /**
     * PDF A4 accesible sin sesion mediante un enlace firmado (se comparte por WhatsApp).
     * La firma cubre el id y la fecha de vencimiento: no se puede adivinar ni alterar.
     */
    public function publico(Comprobante $comprobante)
    {
        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return $this->pdf->a4($comprobante)->inline("{$numero}.pdf");
    }

    /** Enlace firmado de 90 dias al PDF del comprobante. */
    public static function enlacePublico(Comprobante $comprobante): string
    {
        return URL::temporarySignedRoute('comprobantes.publico', now()->addDays(90), ['comprobante' => $comprobante->id]);
    }

    /** Manda el comprobante (PDF y XML) al correo indicado; opcionalmente lo guarda en la ficha del cliente. */
    public function correo(Request $request, Comprobante $comprobante): RedirectResponse
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $datos = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'guardar_en_cliente' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Ingresa el correo del cliente.',
            'email.email' => 'El correo no es válido.',
        ]);

        if ($request->boolean('guardar_en_cliente') && $comprobante->cliente && blank($comprobante->cliente->email)) {
            $comprobante->cliente->update(['email' => $datos['email']]);
        }

        // el PDF y el SMTP tardan: se hace despues de responder
        EnviarComprobantePorCorreo::dispatchAfterResponse($comprobante->id, $datos['email']);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return back()->with('success', "El comprobante {$numero} se está enviando a {$datos['email']}.");
    }

    /** Descarga el XML firmado que se envió a SUNAT. */
    public function xml(Request $request, Comprobante $comprobante)
    {
        return $this->descargarArchivoSunat($request, $comprobante, 'xml_url');
    }

    /** Descarga el CDR (constancia de recepción) que devolvió SUNAT. */
    public function cdr(Request $request, Comprobante $comprobante)
    {
        return $this->descargarArchivoSunat($request, $comprobante, 'cdr_url');
    }

    private function descargarArchivoSunat(Request $request, Comprobante $comprobante, string $campo)
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        $ruta = $comprobante->sunat?->{$campo};

        abort_unless($ruta && Storage::exists($ruta), 404);

        return Storage::download($ruta, basename($ruta));
    }

    /**
     * Envía (o reenvía) a SUNAT un comprobante pendiente o rechazado, incluidas
     * las notas de crédito. Sobre una baja en proceso, vuelve a consultarla.
     */
    public function enviarSunat(Request $request, Comprobante $comprobante, SunatService $sunat): RedirectResponse
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        if (! in_array($comprobante->tipo_comprobante_codigo, ['01', '03', '07'], true)) {
            return back()->with('error', 'Este tipo de comprobante no se envía a SUNAT.');
        }

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        // con una baja en camino lo unico que procede es consultarla; si SUNAT la
        // confirmo, aqui mismo se completa la anulacion interna
        if ($comprobante->sunat?->estado === 'baja_pendiente') {
            $registro = $this->ventas->confirmarBajaPendiente($comprobante);

            return match ($registro->estado) {
                'baja' => back()->with('success', "SUNAT confirmó la baja de {$numero}. Comprobante anulado y stock repuesto."),
                'baja_pendiente' => back()->with('error', "La baja de {$numero} aún no se confirma: {$registro->mensaje_sunat}"),
                default => back()->with('error', "{$registro->mensaje_sunat} El comprobante sigue vigente."),
            };
        }

        if ($comprobante->estado !== 'emitido') {
            return back()->with('error', 'No se puede enviar a SUNAT un comprobante anulado.');
        }

        // un comprobante electronico ya emitido debe llegar a SUNAT aunque la
        // empresa haya desactivado la facturacion despues: la obligacion sigue
        return $this->respuestaDeEnvio($comprobante, $sunat->emitir($comprobante));
    }

    /** Corrige los datos del cliente desde su ficha y reenvía un comprobante rechazado con el mismo número. */
    public function reemitir(Request $request, Comprobante $comprobante): RedirectResponse
    {
        abort_unless($comprobante->empresa_id === $request->user()->empresa_id, 403);

        try {
            $registro = $this->ventas->reemitir($comprobante);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->respuestaDeEnvio($comprobante, $registro);
    }

    /** Convierte una nota de venta en boleta o factura y la envía a SUNAT. */
    public function convertir(Request $request, Comprobante $comprobante): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($comprobante->empresa_id === $usuario->empresa_id, 403);

        $datos = $request->validate([
            'tipo' => ['required', 'in:01,03'],
            'cliente_id' => ['nullable', 'uuid'],
        ], [
            'tipo.in' => 'Elige boleta o factura.',
        ]);

        try {
            $this->ventas->convertir($comprobante, $usuario, $datos['tipo'], $datos['cliente_id'] ?? null);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo convertir la nota de venta. Intenta de nuevo.');
        }

        EnviarComprobanteSunat::dispatchAfterResponse($comprobante->id);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
        $nombre = $datos['tipo'] === '01' ? 'Factura' : 'Boleta';

        return back()
            ->with('ticket', route('comprobantes.ticket', $comprobante))
            ->with('success', "{$nombre} {$numero} emitida a partir de la nota de venta. Se envía a SUNAT.");
    }

    private function respuestaDeEnvio(Comprobante $comprobante, ComprobanteSunat $registro): RedirectResponse
    {
        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        return match ($registro->estado) {
            'aceptado' => back()->with('success', "SUNAT aceptó el comprobante {$numero}."),
            'observado' => back()->with('success', "SUNAT aceptó {$numero} con observaciones: {$registro->mensaje_sunat}"),
            'rechazado' => back()->with('error', "SUNAT rechazó {$numero}: {$registro->mensaje_sunat}"),
            default => back()->with('error', "No se pudo enviar {$numero} a SUNAT: {$registro->mensaje_sunat}"),
        };
    }

    /** Emite una nota de crédito (total o por ítems) sobre una boleta/factura aceptada. */
    public function notaCredito(Request $request, Comprobante $comprobante, NotaCreditoService $notas): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($comprobante->empresa_id === $usuario->empresa_id, 403);

        $esParcial = $request->input('motivo') === '07';

        $datos = $request->validate([
            'motivo' => ['required', Rule::in(array_keys(NotaCreditoService::MOTIVOS))],
            'items' => [$esParcial ? 'required' : 'nullable', 'array'],
            'items.*.detalle_id' => ['required', 'uuid'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'medio_pago_codigo' => ['nullable', Rule::exists('medios_pago', 'codigo')],
            'referencia' => ['nullable', 'string', 'max:100'],
        ], [
            'items.required' => 'Elige los productos a devolver.',
            'medio_pago_codigo.exists' => 'Elige un medio de devolución válido.',
        ]);

        try {
            $nota = $notas->emitir($comprobante, $usuario, $datos);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo emitir la nota de crédito. Intenta de nuevo.');
        }

        EnviarComprobanteSunat::dispatchAfterResponse($nota->id);

        $numero = "{$nota->serie}-".str_pad($nota->correlativo, 6, '0', STR_PAD_LEFT);
        $total = number_format((float) $nota->total, 2);

        return back()
            ->with('ticket', route('comprobantes.ticket', $nota))
            ->with('success', "Nota de crédito {$numero} emitida por S/ {$total}.");
    }

    public function anular(Request $request, Comprobante $comprobante): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($comprobante->empresa_id === $usuario->empresa_id, 403);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:250'],
        ], [
            'motivo.required' => 'Indica el motivo de la anulación.',
        ]);

        try {
            $resultado = $this->ventas->anular($comprobante, $usuario, $datos['motivo']);
        } catch (ErrorDeNegocio $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo anular el comprobante. Intenta de nuevo.');
        }

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);

        if ($resultado === 'baja_pendiente') {
            return back()->with('success', "SUNAT recibió la baja de {$numero} y la está procesando. El comprobante se anulará solo cuando la confirme (o con el botón \"Consultar baja\").");
        }

        return back()->with('success', "Comprobante {$numero} anulado. Stock repuesto.");
    }
}
