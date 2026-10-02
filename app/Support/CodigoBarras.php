<?php

namespace App\Support;

use App\Models\ProductoPresentacion;

/**
 * Códigos de barras internos para productos que no traen uno de fábrica (a granel,
 * artesanales, reempacados). Son EAN-13 con prefijo 20: el rango 20-29 está reservado
 * para uso interno de las tiendas, así que nunca chocan con un código de fabricante
 * y los lee cualquier lector.
 */
final class CodigoBarras
{
    private const PREFIJO = '20';

    /** Dígito de control de un EAN-13 a partir de sus 12 primeros dígitos. */
    public static function digitoControl(string $doce): int
    {
        $suma = 0;
        foreach (str_split($doce) as $i => $digito) {
            $suma += (int) $digito * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $suma % 10) % 10;
    }

    public static function esEan13Valido(string $codigo): bool
    {
        return (bool) preg_match('/^\d{13}$/', $codigo)
            && self::digitoControl(substr($codigo, 0, 12)) === (int) $codigo[12];
    }

    /**
     * Código interno que ninguna presentación de la empresa usa todavía
     * (ni los que se acaban de entregar en esta misma petición: $evitar).
     *
     * @param  list<string>  $evitar
     */
    public static function generar(string $empresaId, array $evitar = []): string
    {
        do {
            $doce = self::PREFIJO.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $codigo = $doce.self::digitoControl($doce);
        } while (
            in_array($codigo, $evitar, true)
            || ProductoPresentacion::where('empresa_id', $empresaId)->where('codigo_barras', $codigo)->exists()
        );

        return $codigo;
    }
}
