<?php

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeNegocio;
use App\Jobs\EnviarComprobanteSunat;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\Empresa;
use App\Models\MedioPago;
use App\Services\NotaCreditoService;
use App\Services\SunatService;
use App\Services\VentaService;
use App\Support\NumeroALetras;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ComprobanteController extends Controller
{
    public function __construct(private readonly VentaService $ventas) {}

    public function index(Request $request): Response
    {
        $filtros = $request->only(['buscar', 'tipo', 'estado', 'sunat']);

        $comprobantes = Comprobante::query()
            ->where('empresa_id', $request->user()->empresa_id)
            // las notas de credito no se listan sueltas: viajan dentro de su comprobante original
            ->where('tipo_comprobante_codigo', '!=', '07')
            ->with([
                'usuario:id,nombre_completo',
                'detalles:id,comprobante_id,descripcion,cantidad,precio_unitario,total',
                'pagos:id,comprobante_id,medio_pago_codigo,monto,referencia',
                'pagos.medioPago:codigo,nombre',
                'sunat:comprobante_id,estado,mensaje_sunat,intentos,enviado_en,xml_url,cdr_url,ticket',
                'notas' => fn ($q) => $q
                    ->select('id', 'comprobante_ref_id', 'serie', 'correlativo', 'motivo_nota', 'total', 'estado', 'tipo_comprobante_codigo', 'creado_en')
                    ->where('estado', 'emitido')
                    ->orderBy('creado_en')
                    ->with('sunat:comprobante_id,estado,mensaje_sunat'),
            ])
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
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo_comprobante_codigo', $tipo))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            // "pendiente" agrupa lo que SUNAT aun no acepto: sin envio, pendiente o rechazado
            ->when($filtros['sunat'] ?? null, fn ($q, $sunat) => $sunat === 'pendiente'
                ? $q->whereIn('tipo_comprobante_codigo', ['01', '03'])
                    ->where(fn ($w) => $w->whereDoesntHave('sunat')->orWhereHas('sunat', fn ($s) => $s->whereIn('estado', ['pendiente', 'rechazado'])))
                : $q->whereHas('sunat', fn ($s) => $s->where('estado', $sunat)))
            ->latest('creado_en')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Comprobantes/Index', [
            'comprobantes' => $comprobantes,
            'filtros' => $filtros,
            'mediosPago' => MedioPago::orderBy('nombre')->get(['codigo', 'nombre', 'requiere_referencia']),
        ]);
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
        $qr = $this->qrDelComprobante($comprobante, $empresa);

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

        $comprobante->load([
            'detalles:id,comprobante_id,descripcion,unidad_codigo,cantidad,precio_unitario,descuento,total',
            'pagos.medioPago:codigo,nombre',
            'sucursal:id,nombre,direccion',
            'comprobanteRef:id,serie,correlativo,tipo_comprobante_codigo',
        ]);

        $numero = "{$comprobante->serie}-".str_pad($comprobante->correlativo, 6, '0', STR_PAD_LEFT);
        $empresa = $request->user()->empresa;

        return SnappyPdf::loadView('pdf.comprobante-a4', [
            'comprobante' => $comprobante,
            'empresa' => $empresa,
            'numero' => $numero,
            'logo' => $empresa->logoParaPdf(),
            'qr' => $this->qrDelComprobante($comprobante, $empresa),
            'hash' => $comprobante->hash_cpe,
            'letras' => NumeroALetras::enSoles((float) $comprobante->total),
            'motivoNota' => NotaCreditoService::MOTIVOS[trim((string) $comprobante->motivo_nota)] ?? null,
        ])
            ->setOption('page-size', 'A4')
            ->setOption('margin-top', '12')
            ->setOption('margin-bottom', '12')
            ->setOption('margin-left', '14')
            ->setOption('margin-right', '14')
            ->setOption('encoding', 'utf-8')
            ->inline("{$numero}.pdf");
    }

    /**
     * QR reglamentario de la representación impresa (solo boletas y facturas):
     * RUC | tipo | serie | correlativo | IGV | total | fecha | doc. cliente | hash.
     */
    private function qrDelComprobante(Comprobante $comprobante, Empresa $empresa): ?string
    {
        if (! in_array($comprobante->tipo_comprobante_codigo, ['01', '03', '07'], true)) {
            return null;
        }

        $contenido = implode('|', [
            $empresa->ruc,
            $comprobante->tipo_comprobante_codigo,
            $comprobante->serie,
            $comprobante->correlativo,
            number_format((float) $comprobante->total_igv, 2, '.', ''),
            number_format((float) $comprobante->total, 2, '.', ''),
            $comprobante->fecha_emision->format('Y-m-d'),
            trim((string) $comprobante->cliente_tipo_doc) ?: '0',
            $comprobante->cliente_numero_doc ?: '-',
            (string) $comprobante->hash_cpe,
        ]);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(300, 1), new SvgImageBackEnd)))
            ->writeString($contenido);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
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
