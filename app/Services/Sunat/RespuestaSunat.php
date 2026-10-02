<?php

namespace App\Services\Sunat;

/**
 * Resultado normalizado de un envío o consulta a SUNAT, independiente de la librería usada.
 */
final class RespuestaSunat
{
    public function __construct(
        public readonly bool $aceptado,
        public readonly string $codigo,
        public readonly string $mensaje,
        public readonly array $observaciones = [],
        public readonly ?string $xml = null,
        public readonly ?string $hash = null,
        public readonly ?string $cdrZip = null,
        public readonly bool $errorComunicacion = false,
        public readonly ?string $ticket = null,
        public readonly bool $enProceso = false,
        // enlace de consulta que SUNAT devuelve en el CDR de una guia (va en el QR)
        public readonly ?string $referencia = null,
    ) {
    }
}
