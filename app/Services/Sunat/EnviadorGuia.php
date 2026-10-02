<?php

namespace App\Services\Sunat;

use App\Exceptions\ErrorDeNegocio;
use App\Models\Empresa;
use App\Support\CertificadoDigital;
use DOMDocument;
use Greenter\Api;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Response\SummaryResult;

/**
 * Capa delgada sobre la API de guías de remisión de SUNAT (plataforma nueva, REST + OAuth).
 * A diferencia de boletas y facturas, la guía no responde con CDR directo: SUNAT entrega
 * un ticket y el resultado se consulta después.
 * En tests se reemplaza por un doble que devuelve respuestas armadas.
 */
class EnviadorGuia
{
    /**
     * SUNAT no ofrece un beta propio para GRE: en pruebas se usa el simulador público que emplea Greenter.
     * Acepta cualquier guía bien formada, así que NO reemplaza una prueba real en producción.
     */
    private const ENDPOINTS_BETA = [
        'auth' => 'https://gre-test.nubefact.com/v1',
        'cpe' => 'https://gre-test.nubefact.com/v1',
    ];

    private const CREDENCIALES_BETA = [
        'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
        'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
        // el simulador solo autentica a su usuario de pruebas, no al usuario SOL de la empresa
        'ruc' => '20161515648',
        'usuario' => 'MODDATOS',
        'clave' => 'MODDATOS',
    ];

    /** Firma y envía la guía. Si SUNAT la recibe, la respuesta queda "en proceso" con su ticket. */
    public function enviar(Empresa $empresa, Despatch $guia): RespuestaSunat
    {
        $api = $this->armarApi($empresa);

        // el simulador de pruebas solo recibe documentos de su propio RUC: en beta la guia viaja
        // con ese RUC (no valida el contenido; sirve para ejercitar el flujo ticket -> aceptada)
        if ($empresa->entorno_sunat !== 'produccion' && $guia->getCompany()) {
            $guia = (clone $guia)->setCompany((clone $guia->getCompany())->setRuc(self::CREDENCIALES_BETA['ruc']));
        }

        $resultado = $api->send($guia);
        $xml = $api->getLastXml();
        $hash = $xml ? $this->hashDelXml($xml) : null;

        if ($resultado?->isSuccess()) {
            return new RespuestaSunat(
                aceptado: false, // aun no: queda en proceso hasta consultar el ticket
                codigo: '98',
                mensaje: 'Guía recibida por SUNAT, en proceso.',
                xml: $xml,
                hash: $hash,
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
            hash: $hash,
            errorComunicacion: ! $this->esRechazo($codigo),
        );
    }

    /** Consulta el ticket: 0 aceptada, 98 en proceso, 99 procesada con errores (rechazo). */
    public function consultarTicket(Empresa $empresa, string $ticket): RespuestaSunat
    {
        $estado = $this->armarApi($empresa)->getStatus($ticket);
        $codigoEnvio = trim((string) $estado->getCode());

        if ($codigoEnvio === '98') {
            return new RespuestaSunat(
                aceptado: false,
                codigo: '98',
                mensaje: 'SUNAT aún está procesando la guía.',
                ticket: $ticket,
                enProceso: true,
            );
        }

        $cdr = $estado->getCdrResponse();
        $error = $estado->getError();

        if ($codigoEnvio === '0' && ! $error) {
            return new RespuestaSunat(
                aceptado: true,
                codigo: trim((string) ($cdr?->getCode() ?? '0')),
                mensaje: (string) ($cdr?->getDescription() ?? 'Guía aceptada por SUNAT.'),
                observaciones: $cdr?->getNotes() ?? [],
                cdrZip: $estado->getCdrZip(),
                ticket: $ticket,
                referencia: $cdr?->getReference(),
            );
        }

        $codigo = trim((string) ($error?->getCode() ?? $cdr?->getCode() ?? $codigoEnvio));

        return new RespuestaSunat(
            aceptado: false,
            codigo: $codigo,
            mensaje: (string) ($error?->getMessage() ?? $cdr?->getDescription() ?? 'SUNAT devolvió un error al procesar la guía.'),
            cdrZip: $estado->getCdrZip(),
            // 99 = SUNAT la proceso y la rechazo; cualquier otra cosa (red, token) admite reintento
            errorComunicacion: $codigoEnvio !== '99',
            ticket: $ticket,
        );
    }

    protected function armarApi(Empresa $empresa): Api
    {
        $produccion = $empresa->entorno_sunat === 'produccion';

        if ($produccion && (blank($empresa->gre_client_id) || blank($empresa->gre_client_secret))) {
            throw new ErrorDeNegocio('Faltan las credenciales de la API de guías (Client ID y Client Secret). Cárgalas en la pantalla de Empresa.');
        }

        $api = new Api($produccion ? null : self::ENDPOINTS_BETA);

        return $api
            ->setApiCredentials(
                $produccion ? (string) $empresa->gre_client_id : self::CREDENCIALES_BETA['client_id'],
                $produccion ? (string) $empresa->gre_client_secret : self::CREDENCIALES_BETA['client_secret'],
            )
            ->setClaveSOL(
                $produccion ? $empresa->ruc : self::CREDENCIALES_BETA['ruc'],
                $produccion ? (string) $empresa->usuario_sol : self::CREDENCIALES_BETA['usuario'],
                $produccion ? (string) $empresa->clave_sol : self::CREDENCIALES_BETA['clave'],
            )
            ->setCertificate($this->certificadoPem($empresa));
    }

    /** Los errores de validación (códigos numéricos 2000-3999) son rechazo definitivo; el resto admite reintento. */
    private function esRechazo(string $codigo): bool
    {
        return is_numeric($codigo) && (int) $codigo >= 2000 && (int) $codigo < 4000;
    }

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
