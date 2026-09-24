<?php

namespace App\Support;

use App\Exceptions\ErrorDeNegocio;
use Carbon\Carbon;

/**
 * Lectura del certificado digital de facturación: acepta un PEM pegado (con
 * la llave protegida o no) o un PFX/P12 en base64, y lo normaliza al PEM
 * llave + certificado que espera el firmador.
 */
final class CertificadoDigital
{
    /**
     * @return array{pem: string, vence_en: Carbon, emitido_a: ?string, ruc: ?string}
     *
     * @throws ErrorDeNegocio
     */
    public static function analizar(?string $contenido, ?string $clave): array
    {
        $contenido = trim((string) $contenido);
        $clave = (string) $clave;

        if ($contenido === '') {
            throw new ErrorDeNegocio('Pega el contenido del certificado (.pem) o el .pfx en base64.');
        }

        [$llavePem, $certPem] = str_contains($contenido, '-----BEGIN')
            ? self::desdePem($contenido, $clave)
            : self::desdePfx($contenido, $clave);

        $datos = openssl_x509_parse($certPem);

        if (! $datos) {
            throw new ErrorDeNegocio('El certificado no se pudo leer. Verifica el archivo.');
        }

        $vence = Carbon::createFromTimestamp($datos['validTo_time_t']);

        if ($vence->isPast()) {
            throw new ErrorDeNegocio('El certificado venció el '.$vence->format('d/m/Y').'. Renuévalo con tu proveedor antes de cargarlo.');
        }

        $sujeto = $datos['subject'] ?? [];
        $serial = (string) ($sujeto['serialNumber'] ?? '');
        preg_match('/(\d{11})/', $serial, $coincidencia);

        return [
            'pem' => $llavePem.$certPem,
            'vence_en' => $vence,
            'emitido_a' => $sujeto['CN'] ?? null,
            'ruc' => $coincidencia[1] ?? null,
        ];
    }

    /** PEM listo para firmar; el contenido ya fue validado al guardarlo. */
    public static function pem(?string $contenido, ?string $clave): string
    {
        return self::analizar($contenido, $clave)['pem'];
    }

    /** @return array{0: string, 1: string} */
    private static function desdePem(string $contenido, string $clave): array
    {
        $llave = @openssl_pkey_get_private($contenido, $clave !== '' ? $clave : null);
        $certificado = @openssl_x509_read($contenido);

        if (! $certificado) {
            throw new ErrorDeNegocio('El PEM no contiene un certificado (-----BEGIN CERTIFICATE-----).');
        }

        if (! $llave) {
            throw new ErrorDeNegocio($clave !== ''
                ? 'No se pudo abrir la llave privada con esa contraseña. Revisa la contraseña del certificado.'
                : 'El PEM no contiene la llave privada, o está protegida: indica la contraseña del certificado.');
        }

        if (! openssl_x509_export($certificado, $certPem)) {
            throw new ErrorDeNegocio('El certificado no se pudo procesar.');
        }

        // en Windows sin openssl.cnf la exportacion de la llave falla aunque la llave sea valida:
        // se usa el bloque PEM tal como vino (el firmador lo entiende igual)
        if (! @openssl_pkey_export($llave, $llavePem)) {
            preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', $contenido, $bloque);
            $llavePem = ($bloque[0] ?? '')."\n";
        }

        return [$llavePem, $certPem];
    }

    /** @return array{0: string, 1: string} */
    private static function desdePfx(string $contenido, string $clave): array
    {
        $pfx = base64_decode(preg_replace('/\s+/', '', $contenido) ?? '', true);

        if ($pfx === false || ! @openssl_pkcs12_read($pfx, $partes, $clave)) {
            throw new ErrorDeNegocio('El .pfx no se pudo abrir. Verifica que esté en base64 y que la contraseña sea la correcta.');
        }

        return [(string) ($partes['pkey'] ?? ''), (string) ($partes['cert'] ?? '')];
    }
}
