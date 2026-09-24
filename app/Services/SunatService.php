<?php

namespace App\Services;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Comprobante;
use App\Models\ComprobanteSunat;
use App\Models\Ubigeo;
use App\Services\Sunat\EnviadorSunat;
use App\Services\Sunat\RespuestaSunat;
use App\Support\NumeroALetras;
use Carbon\Carbon;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Cuota;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SunatService
{
    public function __construct(private readonly EnviadorSunat $enviador)
    {
    }

    /**
     * Envía a SUNAT una boleta o factura emitida y registra el resultado.
     * Nunca lanza: cualquier falla deja el registro en "pendiente" con el
     * motivo, para reintentarlo luego.
     */
    public function emitir(Comprobante $comprobante): ComprobanteSunat
    {
        // el envio tras la venta, el boton de reenvio y el comando programado
        // no deben mandar el mismo comprobante a la vez
        $lock = Cache::lock("sunat:emitir:{$comprobante->id}", 120);

        if (! $lock->get()) {
            return ComprobanteSunat::firstOrNew(['comprobante_id' => $comprobante->id]);
        }

        try {
            $registro = ComprobanteSunat::firstOrNew(['comprobante_id' => $comprobante->id]);

            // un comprobante ya aceptado (o en baja) no debe reenviarse
            if (in_array($registro->estado, ['aceptado', 'observado', 'baja_pendiente', 'baja'], true)) {
                return $registro;
            }

            $registro->intentos = (int) ($registro->intentos ?? 0) + 1;
            $registro->enviado_en = now();

            try {
                $documento = $comprobante->tipo_comprobante_codigo === '07'
                    ? $this->construirNote($comprobante)
                    : $this->construirInvoice($comprobante);

                $respuesta = $this->enviador->enviar($comprobante->empresa, $documento);
            } catch (\Throwable $e) {
                report($e);

                $registro->estado = 'pendiente';
                $registro->mensaje_sunat = 'No se pudo enviar: ' . $e->getMessage();
                $registro->save();

                $this->reflejarEnComprobante($comprobante, $registro, null);

                return $registro;
            }

            $this->guardarResultado($comprobante, $registro, $respuesta);

            return $registro;
        } finally {
            $lock->release();
        }
    }

    /** Arma el documento UBL (boleta 03 / factura 01) desde el comprobante guardado. */
    public function construirInvoice(Comprobante $comprobante): Invoice
    {
        $comprobante->loadMissing(['empresa', 'sucursal', 'detalles']);

        $emision = Carbon::parse(
            $comprobante->fecha_emision->format('Y-m-d') . ' ' . ($comprobante->hora_emision ?? '00:00:00'),
            'America/Lima',
        );

        $totalGravado = (float) $comprobante->total_gravado;
        $totalExonerado = (float) $comprobante->total_exonerado;
        $totalInafecto = (float) $comprobante->total_inafecto;
        $totalIgv = (float) $comprobante->total_igv;
        $total = (float) $comprobante->total;

        $invoice = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101') // venta interna
            ->setTipoDoc($comprobante->tipo_comprobante_codigo)
            ->setSerie($comprobante->serie)
            ->setCorrelativo((string) $comprobante->correlativo)
            ->setFechaEmision($emision)
            ->setTipoMoneda(trim((string) $comprobante->moneda) ?: 'PEN')
            ->setCompany($this->companyDe($comprobante))
            ->setClient($this->clienteDe($comprobante))
            ->setMtoOperGravadas($totalGravado)
            ->setMtoOperExoneradas($totalExonerado)
            ->setMtoOperInafectas($totalInafecto)
            ->setMtoIGV($totalIgv)
            ->setTotalImpuestos($totalIgv)
            ->setValorVenta(round($totalGravado + $totalExonerado + $totalInafecto, 2))
            ->setSubTotal($total)
            ->setMtoImpVenta($total);

        if ($comprobante->es_credito) {
            $vencimiento = $comprobante->fecha_vencimiento ?? $comprobante->fecha_emision->copy()->addDays(30);

            $invoice->setFormaPago(new FormaPagoCredito($total))
                ->setCuotas([(new Cuota())
                    ->setMoneda('PEN')
                    ->setMonto($total)
                    ->setFechaPago(Carbon::parse($vencimiento->format('Y-m-d'), 'America/Lima'))]);
        } else {
            $invoice->setFormaPago(new FormaPagoContado());
        }

        return $invoice
            ->setDetails($this->detallesUbl($comprobante))
            ->setLegends([(new Legend())->setCode('1000')->setValue('SON ' . NumeroALetras::enSoles($total))]);
    }

    /** Arma la nota de crédito UBL (tipo 07) que modifica una boleta o factura. */
    public function construirNote(Comprobante $comprobante): Note
    {
        $comprobante->loadMissing(['empresa', 'sucursal', 'detalles', 'comprobanteRef']);
        $referencia = $comprobante->comprobanteRef;

        $emision = Carbon::parse(
            $comprobante->fecha_emision->format('Y-m-d') . ' ' . ($comprobante->hora_emision ?? '00:00:00'),
            'America/Lima',
        );

        $totalGravado = (float) $comprobante->total_gravado;
        $totalExonerado = (float) $comprobante->total_exonerado;
        $totalInafecto = (float) $comprobante->total_inafecto;
        $totalIgv = (float) $comprobante->total_igv;
        $total = (float) $comprobante->total;

        return (new Note())
            ->setUblVersion('2.1')
            ->setTipoDoc('07')
            ->setSerie($comprobante->serie)
            ->setCorrelativo((string) $comprobante->correlativo)
            ->setFechaEmision($emision)
            ->setTipoMoneda(trim((string) $comprobante->moneda) ?: 'PEN')
            ->setTipDocAfectado($referencia->tipo_comprobante_codigo)
            ->setNumDocfectado("{$referencia->serie}-{$referencia->correlativo}")
            ->setCodMotivo(trim((string) $comprobante->motivo_nota))
            ->setDesMotivo(NotaCreditoService::MOTIVOS[trim((string) $comprobante->motivo_nota)] ?? 'Nota de crédito')
            ->setCompany($this->companyDe($comprobante))
            ->setClient($this->clienteDe($comprobante))
            ->setMtoOperGravadas($totalGravado)
            ->setMtoOperExoneradas($totalExonerado)
            ->setMtoOperInafectas($totalInafecto)
            ->setMtoIGV($totalIgv)
            ->setTotalImpuestos($totalIgv)
            ->setValorVenta(round($totalGravado + $totalExonerado + $totalInafecto, 2))
            ->setSubTotal($total)
            ->setMtoImpVenta($total)
            ->setDetails($this->detallesUbl($comprobante))
            ->setLegends([(new Legend())->setCode('1000')->setValue('SON ' . NumeroALetras::enSoles($total))]);
    }

    /** @return SaleDetail[] */
    private function detallesUbl(Comprobante $comprobante): array
    {
        return $comprobante->detalles->map(function ($d) {
            $cantidad = (float) $d->cantidad;
            $igv = (float) $d->igv;
            $totalLinea = (float) $d->total; // con IGV y con el descuento ya aplicado
            $valorVenta = round($totalLinea - $igv, 2);
            $esGravado = trim((string) $d->tipo_afectacion_codigo) === '10';

            // el valor unitario se deriva del importe neto para que la aritmetica
            // del XML cierre exacta aunque la linea tenga descuento
            return (new SaleDetail())
                ->setUnidad(trim((string) $d->unidad_codigo) ?: 'NIU')
                ->setDescripcion($d->descripcion)
                ->setCantidad($cantidad)
                ->setMtoValorUnitario($cantidad > 0 ? round($valorVenta / $cantidad, 10) : 0.0)
                ->setMtoValorVenta($valorVenta)
                ->setMtoBaseIgv($esGravado ? $valorVenta : round($totalLinea, 2))
                ->setPorcentajeIgv($esGravado ? 18.0 : 0.0)
                ->setIgv($igv)
                ->setTipAfeIgv(trim((string) $d->tipo_afectacion_codigo) ?: '10')
                ->setTotalImpuestos($igv)
                ->setMtoPrecioUnitario($cantidad > 0 ? round($totalLinea / $cantidad, 10) : 0.0);
        })->all();
    }

    /**
     * Comunica a SUNAT la baja de un comprobante ya aceptado: RA para
     * facturas, resumen diario con condición 3 para boletas. Lanza
     * ErrorDeNegocio si la baja no procede, SUNAT no la recibe o la rechaza,
     * para que la anulación interna no continúe.
     *
     * Si SUNAT la recibe pero aún la procesa, el registro queda en
     * 'baja_pendiente' y la anulación interna se completa al confirmarla
     * (ver VentaService::confirmarBajaPendiente).
     *
     * @throws ErrorDeNegocio
     */
    public function solicitarBaja(Comprobante $comprobante, string $motivo, ?string $usuarioId = null): ComprobanteSunat
    {
        $registro = ComprobanteSunat::firstOrNew(['comprobante_id' => $comprobante->id]);

        // ya hay una baja en camino: solo cabe volver a consultarla
        if ($registro->estado === 'baja_pendiente') {
            return $this->consultarBaja($comprobante);
        }

        // sin aceptacion previa no hay nada que comunicar (y una baja no se repite)
        if (! in_array($registro->estado, ['aceptado', 'observado'], true)) {
            return $registro;
        }

        $dias = $comprobante->fecha_emision->startOfDay()->diffInDays(now()->startOfDay());

        if ($dias > 7) {
            throw new ErrorDeNegocio(
                'SUNAT ya no admite la baja de este comprobante (pasaron más de 7 días desde su emisión). Corresponde emitir una nota de crédito.'
            );
        }

        $comprobante->loadMissing(['empresa', 'sucursal']);

        // el correlativo diario de RA/RC se toma bajo lock para que dos bajas
        // simultaneas de la misma empresa no repitan numero
        $lock = Cache::lock("sunat:baja:{$comprobante->empresa_id}", 60);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            throw new ErrorDeNegocio('Hay otra baja en curso para esta empresa. Intenta de nuevo en unos segundos.');
        }

        try {
            $esFactura = $comprobante->tipo_comprobante_codigo === '01';
            $correlativo = $this->correlativoDeBajaDelDia($comprobante->empresa_id);

            $documento = $esFactura
                ? $this->construirComunicacionDeBaja($comprobante, $correlativo, $motivo)
                : $this->construirResumenDeBaja($comprobante, $correlativo);

            try {
                $respuesta = $this->enviador->enviarBaja($comprobante->empresa, $documento);
            } catch (\Throwable $e) {
                report($e);

                throw new ErrorDeNegocio('No se pudo comunicar la baja a SUNAT: ' . $e->getMessage());
            }

            if (! $respuesta->enProceso) {
                throw new ErrorDeNegocio("SUNAT no aceptó la comunicación de baja: [{$respuesta->codigo}] {$respuesta->mensaje}");
            }

            $registro->estado = 'baja_pendiente';
            $registro->ticket = $respuesta->ticket;
            $registro->mensaje_sunat = 'Baja en proceso (ticket ' . $respuesta->ticket . ').';
            $registro->save();

            $comprobante->forceFill([
                'sunat_ticket' => $respuesta->ticket,
                'sunat_respuesta' => array_merge((array) $comprobante->sunat_respuesta, [
                    'baja' => [
                        'fecha' => now()->format('Y-m-d'),
                        'correlativo' => $correlativo,
                        'motivo' => $motivo,
                        'ticket' => $respuesta->ticket,
                        'usuario_id' => $usuarioId,
                    ],
                ]),
            ])->save();
        } finally {
            $lock->release();
        }

        // SUNAT suele resolver el ticket en segundos: consultamos de una vez
        $registro = $this->consultarBaja($comprobante);

        if ($registro->estado !== 'baja' && $registro->estado !== 'baja_pendiente') {
            throw new ErrorDeNegocio($registro->mensaje_sunat ?: 'SUNAT rechazó la baja del comprobante.');
        }

        return $registro;
    }

    /**
     * Consulta el ticket de una baja en proceso y actualiza el estado:
     * confirmada → 'baja'; rechazada → vuelve a 'aceptado' (sigue vigente
     * ante SUNAT); aún en proceso o sin respuesta → sigue 'baja_pendiente'.
     */
    public function consultarBaja(Comprobante $comprobante): ComprobanteSunat
    {
        $registro = ComprobanteSunat::firstOrNew(['comprobante_id' => $comprobante->id]);

        if ($registro->estado !== 'baja_pendiente' || blank($registro->ticket)) {
            return $registro;
        }

        try {
            $respuesta = $this->enviador->consultarTicket($comprobante->empresa, $registro->ticket);
        } catch (\Throwable $e) {
            report($e);
            $registro->mensaje_sunat = 'No se pudo consultar la baja: ' . $e->getMessage();
            $registro->save();

            return $registro;
        }

        if ($respuesta->enProceso || $respuesta->errorComunicacion) {
            $registro->mensaje_sunat = $respuesta->enProceso
                ? "Baja en proceso (ticket {$registro->ticket})."
                : "Baja en proceso (ticket {$registro->ticket}); no se pudo consultar: [{$respuesta->codigo}] {$respuesta->mensaje}";
            $registro->save();

            return $registro;
        }

        if ($respuesta->aceptado) {
            if ($respuesta->cdrZip) {
                $carpeta = "sunat/{$comprobante->empresa_id}";
                $nombre = "R-BAJA-{$comprobante->empresa->ruc}-{$comprobante->tipo_comprobante_codigo}-{$comprobante->serie}-{$comprobante->correlativo}";
                Storage::put("{$carpeta}/{$nombre}.zip", $respuesta->cdrZip);
                $registro->cdr_url = "{$carpeta}/{$nombre}.zip";
            }

            $registro->estado = 'baja';
            $registro->mensaje_sunat = trim("[{$respuesta->codigo}] {$respuesta->mensaje}");
        } else {
            // SUNAT rechazo la baja: el comprobante sigue vigente y aceptado ante SUNAT
            $registro->estado = 'aceptado';
            $registro->ticket = null;
            $registro->mensaje_sunat = "SUNAT rechazó la baja: [{$respuesta->codigo}] {$respuesta->mensaje}";
        }

        $registro->save();

        return $registro;
    }

    // ---------------------------------------------------------------

    /** Los RA/RC llevan un correlativo diario por empresa (1 a 999). */
    private function correlativoDeBajaDelDia(string $empresaId): int
    {
        return Comprobante::query()
                ->where('empresa_id', $empresaId)
                ->whereRaw("sunat_respuesta->'baja'->>'fecha' = ?", [now()->format('Y-m-d')])
                ->count() + 1;
    }

    private function construirComunicacionDeBaja(Comprobante $comprobante, int $correlativo, string $motivo): Voided
    {
        return (new Voided())
            ->setCorrelativo(str_pad((string) $correlativo, 5, '0', STR_PAD_LEFT))
            ->setFecGeneracion(Carbon::parse($comprobante->fecha_emision->format('Y-m-d'), 'America/Lima'))
            ->setFecComunicacion(now())
            ->setCompany($this->companyDe($comprobante))
            ->setDetails([(new VoidedDetail())
                ->setTipoDoc($comprobante->tipo_comprobante_codigo)
                ->setSerie($comprobante->serie)
                ->setCorrelativo((string) $comprobante->correlativo)
                ->setDesMotivoBaja(mb_substr($motivo, 0, 100))]);
    }

    private function construirResumenDeBaja(Comprobante $comprobante, int $correlativo): Summary
    {
        return (new Summary())
            ->setCorrelativo(str_pad((string) $correlativo, 3, '0', STR_PAD_LEFT))
            ->setFecGeneracion(Carbon::parse($comprobante->fecha_emision->format('Y-m-d'), 'America/Lima'))
            ->setFecResumen(now())
            ->setMoneda('PEN')
            ->setCompany($this->companyDe($comprobante))
            ->setDetails([(new SummaryDetail())
                ->setTipoDoc($comprobante->tipo_comprobante_codigo)
                ->setSerieNro("{$comprobante->serie}-{$comprobante->correlativo}")
                ->setEstado('3') // condicion 3: baja
                ->setClienteTipo(trim((string) $comprobante->cliente_tipo_doc) ?: '0')
                ->setClienteNro($comprobante->cliente_numero_doc ?: '-')
                ->setTotal((float) $comprobante->total)
                ->setMtoOperGravadas((float) $comprobante->total_gravado)
                ->setMtoOperExoneradas((float) $comprobante->total_exonerado)
                ->setMtoOperInafectas((float) $comprobante->total_inafecto)
                ->setMtoIGV((float) $comprobante->total_igv)]);
    }

    private function companyDe(Comprobante $comprobante): Company
    {
        $empresa = $comprobante->empresa;

        return (new Company())
            ->setRuc($empresa->ruc)
            ->setRazonSocial($empresa->razon_social)
            ->setNombreComercial($empresa->nombre_comercial ?: $empresa->razon_social)
            ->setAddress($this->direccionEmisor($comprobante->sucursal));
    }

    /**
     * Domicilio del emisor con departamento/provincia/distrito resueltos desde
     * el catálogo de ubigeos; sin ellos SUNAT observa el comprobante (4096-4098).
     */
    private function direccionEmisor($sucursal): Address
    {
        $codigo = trim((string) ($sucursal?->ubigeo ?? ''));
        $ubigeo = $codigo !== '' ? Ubigeo::find($codigo) : null;

        return (new Address())
            ->setUbigueo($codigo ?: null)
            ->setDepartamento($ubigeo?->departamento)
            ->setProvincia($ubigeo?->provincia)
            ->setDistrito($ubigeo?->distrito)
            ->setDireccion($sucursal?->direccion ?: '-');
    }

    private function clienteDe(Comprobante $comprobante): Client
    {
        if (blank($comprobante->cliente_numero_doc)) {
            // boleta sin identificar: cliente generico (catalogo 06, tipo 0)
            return (new Client())
                ->setTipoDoc('0')
                ->setNumDoc('-')
                ->setRznSocial('CLIENTES VARIOS');
        }

        $cliente = (new Client())
            ->setTipoDoc(trim((string) $comprobante->cliente_tipo_doc))
            ->setNumDoc(trim((string) $comprobante->cliente_numero_doc))
            ->setRznSocial($comprobante->cliente_nombre ?: '-');

        if ($comprobante->cliente_direccion) {
            $cliente->setAddress((new Address())->setDireccion($comprobante->cliente_direccion));
        }

        return $cliente;
    }

    private function guardarResultado(Comprobante $comprobante, ComprobanteSunat $registro, RespuestaSunat $respuesta): void
    {
        $carpeta = "sunat/{$comprobante->empresa_id}";
        $nombre = "{$comprobante->empresa->ruc}-{$comprobante->tipo_comprobante_codigo}-{$comprobante->serie}-{$comprobante->correlativo}";

        if ($respuesta->xml) {
            Storage::put("{$carpeta}/{$nombre}.xml", $respuesta->xml);
            $registro->xml_url = "{$carpeta}/{$nombre}.xml";
        }

        if ($respuesta->cdrZip) {
            Storage::put("{$carpeta}/R-{$nombre}.zip", $respuesta->cdrZip);
            $registro->cdr_url = "{$carpeta}/R-{$nombre}.zip";
        }

        $registro->estado = match (true) {
            $respuesta->aceptado && $respuesta->observaciones !== [] => 'observado',
            $respuesta->aceptado => 'aceptado',
            $respuesta->errorComunicacion => 'pendiente',
            default => 'rechazado',
        };

        $registro->hash_cpe = $respuesta->hash;
        $registro->mensaje_sunat = trim("[{$respuesta->codigo}] {$respuesta->mensaje}"
            . ($respuesta->observaciones !== [] ? ' | ' . implode('; ', $respuesta->observaciones) : ''));
        $registro->save();

        $this->reflejarEnComprobante($comprobante, $registro, $respuesta);
    }

    /** Copia el resultado al comprobante para listados y reportes. */
    private function reflejarEnComprobante(Comprobante $comprobante, ComprobanteSunat $registro, ?RespuestaSunat $respuesta): void
    {
        $comprobante->forceFill([
            // el CHECK de comprobantes no admite los estados de baja; los demas coinciden
            'estado_sunat' => in_array($registro->estado, ['baja', 'baja_pendiente'], true) ? 'aceptado' : $registro->estado,
            'hash_cpe' => $registro->hash_cpe,
            'sunat_respuesta' => $respuesta ? [
                'codigo' => $respuesta->codigo,
                'mensaje' => $respuesta->mensaje,
                'observaciones' => $respuesta->observaciones,
            ] : ['mensaje' => $registro->mensaje_sunat],
        ])->save();
    }
}
