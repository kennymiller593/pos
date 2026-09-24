<?php

namespace App\Support;

/**
 * Convierte importes a letras para la leyenda 1000 del comprobante
 * ("SON CIENTO VEINTITRÉS CON 45/100 SOLES").
 */
final class NumeroALetras
{
    private const UNIDADES = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function enSoles(float $monto): string
    {
        $entero = (int) floor($monto);
        $centimos = (int) round(($monto - $entero) * 100);

        if ($centimos === 100) {
            $entero++;
            $centimos = 0;
        }

        $letras = $entero === 0 ? 'CERO' : self::numero($entero);

        return sprintf('%s CON %02d/100 SOLES', $letras, $centimos);
    }

    private static function numero(int $n): string
    {
        return match (true) {
            $n < 30 => self::UNIDADES[$n],
            $n < 100 => self::DECENAS[intdiv($n, 10)] . ($n % 10 !== 0 ? ' Y ' . self::UNIDADES[$n % 10] : ''),
            $n === 100 => 'CIEN',
            $n < 1000 => self::CENTENAS[intdiv($n, 100)] . ($n % 100 !== 0 ? ' ' . self::numero($n % 100) : ''),
            $n < 1000000 => self::miles($n),
            $n < 2000000 => 'UN MILLÓN' . ($n % 1000000 !== 0 ? ' ' . self::numero($n % 1000000) : ''),
            default => self::apocopar(self::numero(intdiv($n, 1000000))) . ' MILLONES' . ($n % 1000000 !== 0 ? ' ' . self::numero($n % 1000000) : ''),
        };
    }

    private static function miles(int $n): string
    {
        $miles = intdiv($n, 1000);
        $prefijo = $miles === 1 ? 'MIL' : self::apocopar(self::numero($miles)) . ' MIL';

        return $prefijo . ($n % 1000 !== 0 ? ' ' . self::numero($n % 1000) : '');
    }

    /** "UNO" se apocopa a "UN" cuando antecede a MIL o MILLONES. */
    private static function apocopar(string $texto): string
    {
        return str_replace(['VEINTIUNO', 'UNO'], ['VEINTIÚN', 'UN'], $texto);
    }
}
