<?php

namespace App\Support;

/**
 * Las fuentes que arma Vite (@fonts) traen cada peso dos veces: en woff2 y en woff. Como son dos
 * @font-face iguales, el navegador se queda con el último (el woff, más pesado) y descarga los dos.
 * Todo navegador actual lee woff2: se deja solo ese.
 */
class Fuentes
{
    public static function soloWoff2(mixed $html): string
    {
        return (string) preg_replace('/@font-face\s*\{[^}]*format\("woff"\)[^}]*\}\s*/', '', (string) $html);
    }
}
