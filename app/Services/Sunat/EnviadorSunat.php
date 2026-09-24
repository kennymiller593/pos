<?php

namespace App\Services\Sunat;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Empresa;
use DOMDocument;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;

/**
 * Capa delgada sobre Greenter: firma el XML con el certificado de la empresa
 * y lo envía al servicio de SUNAT que corresponda a su entorno.
 * En tests se reemplaza por un doble que devuelve respuestas armadas.
 */
class EnviadorSunat
{
    /** Envía un comprobante con CDR directo (boleta/factura Invoice o nota de crédito Note). */
    public function enviar(Empresa $empresa, DocumentInterface $documento): RespuestaSunat
    {
        $see = $this->armarSee($empresa);

        $resultado = $see->send($documento);
        $xml = $see->getFactory()->getLastXml();
        $hash = $xml ? $this->hashDelXml($xml) : null;

        if ($resultado?->isSuccess()) {
            $cdr = $resultado instanceof BillResult ? $resultado->getCdrResponse() : null;

            return new RespuestaSunat(
                aceptado: true,
                codigo: (string) ($cdr?->getCode() ?? '0'),
                mensaje: (string) ($cdr?->getDescription() ?? ''),
                observaciones: $cdr?->getNotes() ?? [],
                xml: $xml,
                hash: $hash,
                cdrZip: $resultado instanceof BillResult ? $resultado->getCdrZip() : null,
            );
        }

        $error = $resultado?->getError();
        $codigo = trim((string) ($error?->getCode() ?? ''));

        // segun SUNAT, los codigos 2000-3999 son rechazo definitivo del comprobante;
        // todo lo demas (red caida, servicio no disponible, excepciones 0100-1999) admite reintento
        $esRechazo = is_numeric($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT no respondió.'),
            xml: $xml,
            hash: $hash,
            errorComunicacion: ! $esRechazo,
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
        $esRechazo = is_numeric($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT no respondió.'),
            xml: $xml,
            errorComunicacion: ! $esRechazo,
        );
    }

    /** Consulta el resultado de un ticket de baja o resumen. */
    public function consultarTicket(Empresa $empresa, string $ticket): RespuestaSunat
    {
        $estado = $this->armarSee($empresa)->getStatus($ticket);

        if ($estado->isSuccess()) {
            $cdr = $estado->getCdrResponse();

            return new RespuestaSunat(
                aceptado: true,
                codigo: (string) ($cdr?->getCode() ?? '0'),
                mensaje: (string) ($cdr?->getDescription() ?? 'Procesado por SUNAT.'),
                observaciones: $cdr?->getNotes() ?? [],
                cdrZip: $estado->getCdrZip(),
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

        return new RespuestaSunat(
            aceptado: false,
            codigo: (string) ($error?->getCode() ?? $estado->getCode() ?? ''),
            mensaje: (string) ($error?->getMessage() ?? 'SUNAT devolvió un error al procesar la comunicación.'),
            ticket: $ticket,
        );
    }

    private function armarSee(Empresa $empresa): See
    {
        $see = new See();
        $see->setCertificate($this->certificadoPem($empresa));
        $see->setService($empresa->entorno_sunat === 'produccion'
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA);
        $see->setClaveSOL($empresa->ruc, (string) $empresa->usuario_sol, (string) $empresa->clave_sol);

        return $see;
    }

    /**
     * Normaliza el certificado guardado a un PEM con llave + certificado,
     * que es lo que espera el firmador. Acepta PEM pegado (con la llave
     * protegida o no) y PFX/P12 codificado en base64.
     */
    private function certificadoPem(Empresa $empresa): string
    {
        $contenido = trim((string) $empresa->certificado_digital);
        $clave = (string) $empresa->clave_certificado;

        if (str_contains($contenido, '-----BEGIN')) {
            $llave = @openssl_pkey_get_private($contenido, $clave !== '' ? $clave : null);
            $certificado = @openssl_x509_read($contenido);

            if ($llave && $certificado && openssl_pkey_export($llave, $llavePem) && openssl_x509_export($certificado, $certPem)) {
                return $llavePem . $certPem;
            }

            return $contenido;
        }

        $pfx = base64_decode(preg_replace('/\s+/', '', $contenido) ?? '', true);

        if ($pfx !== false && @openssl_pkcs12_read($pfx, $partes, $clave)) {
            return ($partes['pkey'] ?? '') . ($partes['cert'] ?? '');
        }

        throw new ErrorDeNegocio('El certificado digital guardado no es válido. Vuelve a cargarlo en la pantalla de Empresa.');
    }

    /** El hash del CPE es el DigestValue de la firma del XML. */
    private function hashDelXml(string $xml): ?string
    {
        $doc = new DOMDocument();

        if (! @$doc->loadXML($xml)) {
            return null;
        }

        $nodos = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'DigestValue');

        return $nodos->length > 0 ? trim($nodos->item(0)->textContent) : null;
    }
}
