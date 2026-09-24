<?php

namespace App\Services\Sunat;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Empresa;
use App\Support\CertificadoDigital;
use DOMDocument;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\See;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\WsdlProvider;

/**
 * Capa delgada sobre Greenter: firma el XML con el certificado de la empresa
 * y lo envía al servicio de SUNAT que corresponda a su entorno.
 * En tests se reemplaza por un doble que devuelve respuestas armadas.
 */
class EnviadorSunat
{
    /** SUNAT responde 1033 cuando el comprobante ya fue registrado (p. ej. un envío anterior cuya respuesta se perdió). */
    private const CODIGO_YA_REGISTRADO = '1033';

    /** Envía un comprobante con CDR directo (boleta/factura Invoice o nota de crédito Note). */
    public function enviar(Empresa $empresa, DocumentInterface $documento): RespuestaSunat
    {
        $see = $this->armarSee($empresa);

        $resultado = $see->send($documento);
        $xml = $see->getFactory()->getLastXml();
        $hash = $xml ? $this->hashDelXml($xml) : null;

        // Greenter marca "success" en cuanto llega un CDR, aunque el CDR traiga un
        // rechazo (2000-3999): hay que mirar el codigo del propio CDR
        if ($resultado?->isSuccess()) {
            $cdr = $resultado instanceof BillResult ? $resultado->getCdrResponse() : null;
            $cdrZip = $resultado instanceof BillResult ? $resultado->getCdrZip() : null;

            return $this->desdeCdr($cdr, $xml, $hash, $cdrZip);
        }

        $error = $resultado?->getError();
        $codigo = trim((string) ($error?->getCode() ?? ''));

        // el comprobante ya esta en SUNAT: recuperamos su CDR en vez de dejarlo pendiente para siempre
        if ($codigo === self::CODIGO_YA_REGISTRADO && ($documento instanceof Invoice || $documento instanceof Note)) {
            $recuperada = $this->consultarCdr($empresa, $documento->getTipoDoc(), $documento->getSerie(), (int) $documento->getCorrelativo());

            if ($recuperada) {
                return new RespuestaSunat(
                    aceptado: $recuperada->aceptado,
                    codigo: $recuperada->codigo,
                    mensaje: $recuperada->mensaje,
                    observaciones: $recuperada->observaciones,
                    xml: $xml,
                    hash: $hash,
                    cdrZip: $recuperada->cdrZip,
                    errorComunicacion: $recuperada->errorComunicacion,
                );
            }
        }

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT no respondió.'),
            xml: $xml,
            hash: $hash,
            errorComunicacion: ! $this->esRechazo($codigo),
        );
    }

    /**
     * Envía una comunicación de baja (RA, facturas) o un resumen diario con
     * condición de baja (RC, boletas). SUNAT los procesa en diferido y
     * responde con un ticket para consultar después.
     */
    public function enviarBaja(Empresa $empresa, Voided|Summary $documento): RespuestaSunat
    {
        $see = $this->armarSee($empresa);

        $resultado = $see->send($documento);
        $xml = $see->getFactory()->getLastXml();

        if ($resultado?->isSuccess()) {
            return new RespuestaSunat(
                aceptado: false, // aun no: queda en proceso hasta consultar el ticket
                codigo: '98',
                mensaje: 'Comunicación recibida por SUNAT, en proceso.',
                xml: $xml,
                hash: $xml ? $this->hashDelXml($xml) : null,
                ticket: $resultado instanceof SummaryResult ? $resultado->getTicket() : null,
                enProceso: true,
            );
        }

        $error = $resultado?->getError();
        $codigo = trim((string) ($error?->getCode() ?? ''));

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT no respondió.'),
            xml: $xml,
            errorComunicacion: ! $this->esRechazo($codigo),
        );
    }

    /**
     * Consulta el resultado de un ticket de baja o resumen. SUNAT responde
     * statusCode 0 (procesado) o 99 (procesado con errores): en ambos casos
     * llega un CDR y es su codigo el que dice si la baja fue aceptada.
     */
    public function consultarTicket(Empresa $empresa, string $ticket): RespuestaSunat
    {
        $estado = $this->armarSee($empresa)->getStatus($ticket);

        if ($estado->isSuccess()) {
            $respuesta = $this->desdeCdr($estado->getCdrResponse(), null, null, $estado->getCdrZip());

            return new RespuestaSunat(
                aceptado: $respuesta->aceptado,
                codigo: $respuesta->codigo,
                mensaje: $respuesta->mensaje ?: 'Procesado por SUNAT.',
                observaciones: $respuesta->observaciones,
                cdrZip: $respuesta->cdrZip,
                errorComunicacion: $respuesta->errorComunicacion,
                ticket: $ticket,
            );
        }

        if ((string) $estado->getCode() === '98') {
            return new RespuestaSunat(
                aceptado: false,
                codigo: '98',
                mensaje: 'SUNAT aún está procesando la comunicación.',
                ticket: $ticket,
                enProceso: true,
            );
        }

        $error = $estado->getError();
        $codigo = trim((string) ($error?->getCode() ?? $estado->getCode() ?? ''));

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT devolvió un error al procesar la comunicación.'),
            ticket: $ticket,
            errorComunicacion: ! $this->esRechazo($codigo),
        );
    }

    /**
     * Pide a SUNAT el CDR de un comprobante ya registrado (servicio de consulta,
     * solo disponible en producción). Devuelve null si no se pudo determinar.
     */
    protected function consultarCdr(Empresa $empresa, string $tipo, string $serie, int $numero): ?RespuestaSunat
    {
        if ($empresa->entorno_sunat !== 'produccion') {
            return null;
        }

        try {
            $cliente = new SoapClient(WsdlProvider::getConsultPath());
            $cliente->setCredentials($empresa->ruc.$empresa->usuario_sol, (string) $empresa->clave_sol);
            $cliente->setService(SunatEndpoints::FE_CONSULTA_CDR);

            $servicio = new ConsultCdrService;
            $servicio->setClient($cliente);

            $resultado = $servicio->getStatusCdr($empresa->ruc, $tipo, $serie, $numero);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $resultado->isSuccess() || ! $resultado->getCdrResponse()) {
            return null;
        }

        return $this->desdeCdr($resultado->getCdrResponse(), null, null, $resultado->getCdrZip());
    }

    /**
     * Traduce un CDR a nuestra respuesta: codigo 0 o >= 4000 es aceptado (4000+
     * son observaciones), 2000-3999 es rechazo definitivo y el resto admite reintento.
     */
    private function desdeCdr(?CdrResponse $cdr, ?string $xml, ?string $hash, ?string $cdrZip): RespuestaSunat
    {
        $codigo = trim((string) ($cdr?->getCode() ?? '0'));
        $aceptado = $cdr === null || $cdr->isAccepted();

        return new RespuestaSunat(
            aceptado: $aceptado,
            codigo: $codigo,
            mensaje: (string) ($cdr?->getDescription() ?? ''),
            observaciones: $cdr?->getNotes() ?? [],
            xml: $xml,
            hash: $hash,
            cdrZip: $cdrZip,
            errorComunicacion: ! $aceptado && ! $this->esRechazo($codigo),
        );
    }

    /** Segun SUNAT, los codigos 2000-3999 son rechazo definitivo; el resto (0100-1999, red caida) admite reintento. */
    private function esRechazo(string $codigo): bool
    {
        return is_numeric($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;
    }

    protected function armarSee(Empresa $empresa): See
    {
        $see = new See;
        $see->setCertificate($this->certificadoPem($empresa));
        $see->setService($empresa->entorno_sunat === 'produccion'
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA);
        $see->setClaveSOL($empresa->ruc, (string) $empresa->usuario_sol, (string) $empresa->clave_sol);

        return $see;
    }

    /** PEM llave + certificado que espera el firmador (ver App\Support\CertificadoDigital). */
    private function certificadoPem(Empresa $empresa): string
    {
        try {
            return CertificadoDigital::pem($empresa->certificado_digital, $empresa->clave_certificado);
        } catch (ErrorDeNegocio $e) {
            throw new ErrorDeNegocio('El certificado digital guardado no es válido ('.$e->getMessage().'). Vuelve a cargarlo en la pantalla de Empresa.');
        }
    }

    /** El hash del CPE es el DigestValue de la firma del XML. */
    private function hashDelXml(string $xml): ?string
    {
        $doc = new DOMDocument;

        if (! @$doc->loadXML($xml)) {
            return null;
        }

        $nodos = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'DigestValue');

        return $nodos->length > 0 ? trim($nodos->item(0)->textContent) : null;
    }
}
