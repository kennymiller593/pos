<?php

namespace App\Support;

/**
 * Validación de documentos de identidad según el catálogo 06 de SUNAT:
 * 0 sin documento, 1 DNI, 4 carnet de extranjería, 6 RUC, 7 pasaporte.
 */
final class DocumentoIdentidad
{
    public const SIN_DOCUMENTO = '0';

    public const DNI = '1';

    public const CARNET_EXTRANJERIA = '4';

    public const RUC = '6';

    public const PASAPORTE = '7';

    /** Nombre corto para mensajes al usuario. */
    private const NOMBRES = [
        self::DNI => 'DNI',
        self::CARNET_EXTRANJERIA => 'carnet de extranjería',
        self::RUC => 'RUC',
        self::PASAPORTE => 'pasaporte',
    ];

    public static function nombre(?string $tipo): string
    {
        return self::NOMBRES[trim((string) $tipo)] ?? 'documento';
    }

    /** true si el número tiene el formato (y dígito verificador) que exige el tipo. */
    public static function esValido(?string $tipo, ?string $numero): bool
    {
        $tipo = trim((string) $tipo);
        $numero = trim((string) $numero);

        return match ($tipo) {
            self::SIN_DOCUMENTO => $numero === '',
            self::DNI => (bool) preg_match('/^\d{8}$/', $numero),
            self::RUC => self::rucValido($numero),
            self::CARNET_EXTRANJERIA => (bool) preg_match('/^[A-Za-z0-9]{6,12}$/', $numero),
            self::PASAPORTE => (bool) preg_match('/^[A-Za-z0-9]{6,15}$/', $numero),
            default => $numero !== '' && strlen($numero) <= 15,
        };
    }

    /** Mensaje de validación para el tipo, listo para mostrar. */
    public static function mensaje(?string $tipo): string
    {
        return match (trim((string) $tipo)) {
            self::SIN_DOCUMENTO => 'Con "Sin documento" deja el número vacío.',
            self::DNI => 'El DNI debe tener 8 dígitos.',
            self::RUC => 'El RUC debe tener 11 dígitos válidos (revisa el dígito final).',
            self::CARNET_EXTRANJERIA => 'El carnet de extranjería debe tener entre 6 y 12 caracteres.',
            self::PASAPORTE => 'El pasaporte debe tener entre 6 y 15 caracteres.',
            default => 'El número de documento no es válido.',
        };
    }

    /**
     * Regla de validación de Laravel para `numero_documento` según el tipo enviado.
     * Se usa como closure: fn ($atributo, $valor, $falla).
     */
    public static function regla(?string $tipo): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla) use ($tipo) {
            if (! self::esValido($tipo, (string) $valor)) {
                $falla(self::mensaje($tipo));
            }
        };
    }

    /** RUC de 11 dígitos con dígito verificador (módulo 11) correcto. */
    public static function rucValido(string $ruc): bool
    {
        if (! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return false;
        }

        return (int) $ruc[10] === self::digitoVerificadorRuc(substr($ruc, 0, 10));
    }

    /** Completa los 10 primeros dígitos de un RUC con su dígito verificador. */
    public static function completarRuc(string $diezDigitos): string
    {
        return $diezDigitos.self::digitoVerificadorRuc($diezDigitos);
    }

    private static function digitoVerificadorRuc(string $diezDigitos): int
    {
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;

        foreach ($pesos as $i => $peso) {
            $suma += (int) $diezDigitos[$i] * $peso;
        }

        $resto = 11 - ($suma % 11);

        return $resto >= 10 ? $resto - 10 : $resto;
    }
}
