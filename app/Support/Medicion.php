<?php

namespace App\Support;

/**
 * Herramientas de medición que el dueño conecta a su tienda en línea (Google Analytics, Meta Pixel,
 * TikTok Pixel, Microsoft Clarity y la verificación de Google Search Console).
 *
 * Solo se guarda el identificador de cada una, nunca el código que pegó el dueño: el script lo pone
 * la tienda desde su propio JavaScript (tienda.js), que es lo único que permite su política de
 * contenido (CSP). Así un código mal pegado no puede romper ni inyectar nada en la tienda.
 */
class Medicion
{
    /**
     * Cada herramienta: clave en tienda_config, patrón del identificador dentro de un código pegado y
     * patrón del identificador escrito solo, y los dominios que su script necesita en la CSP.
     */
    public const HERRAMIENTAS = [
        'medicion_ga4' => [
            'nombre' => 'Google Analytics',
            'en_codigo' => '/\b(G-[A-Z0-9]{6,14})\b/i',
            'solo' => '/^G-[A-Z0-9]{6,14}$/i',
            'script' => ['https://www.googletagmanager.com'],
            'conexion' => ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://analytics.google.com', 'https://stats.g.doubleclick.net'],
        ],
        'medicion_meta' => [
            'nombre' => 'Meta Pixel',
            'en_codigo' => '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{8,20})[\'"]|facebook\.com\/tr\?id=(\d{8,20})/i',
            'solo' => '/^\d{8,20}$/',
            'script' => ['https://connect.facebook.net'],
            'conexion' => ['https://www.facebook.com', 'https://connect.facebook.net'],
        ],
        'medicion_tiktok' => [
            'nombre' => 'TikTok Pixel',
            'en_codigo' => '/ttq\.load\(\s*[\'"]([A-Z0-9]{10,40})[\'"]|sdkid=([A-Z0-9]{10,40})/i',
            'solo' => '/^[A-Z0-9]{10,40}$/i',
            'script' => ['https://analytics.tiktok.com'],
            'conexion' => ['https://analytics.tiktok.com'],
        ],
        'medicion_clarity' => [
            'nombre' => 'Microsoft Clarity',
            'en_codigo' => '/clarity\.ms\/tag\/([a-z0-9]{5,20})|[\'"]clarity[\'"]\s*,\s*[\'"]script[\'"]\s*,\s*[\'"]([a-z0-9]{5,20})[\'"]/i',
            'solo' => '/^[a-z0-9]{5,20}$/i',
            'script' => ['https://www.clarity.ms', 'https://scripttags.clarity.ms'],
            'conexion' => ['https://*.clarity.ms'],
        ],
        'medicion_google_verificacion' => [
            'nombre' => 'Google Search Console',
            'en_codigo' => '/google-site-verification[\'"]?\s*content=[\'"]([A-Za-z0-9_-]{20,100})[\'"]/i',
            'solo' => '/^[A-Za-z0-9_-]{20,100}$/',
            'script' => [],
            'conexion' => [],
        ],
    ];

    /**
     * El identificador de una herramienta a partir de lo que pegó el dueño: el código completo que le
     * dio la plataforma o solo el identificador. null si no hay nada; false si hay texto pero no se
     * reconoce.
     */
    public static function extraer(string $clave, ?string $texto): string|false|null
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return null;
        }
        $h = self::HERRAMIENTAS[$clave];

        if (preg_match($h['solo'], $texto)) {
            return self::normalizar($clave, $texto);
        }
        if (preg_match($h['en_codigo'], $texto, $m)) {
            // el patrón tiene dos alternativas: la que haya coincidido
            return self::normalizar($clave, array_values(array_filter(array_slice($m, 1)))[0] ?? false);
        }

        return false;
    }

    private static function normalizar(string $clave, string|false $id): string|false
    {
        if ($id === false) {
            return false;
        }

        return match ($clave) {
            'medicion_ga4', 'medicion_tiktok' => strtoupper($id),
            'medicion_clarity' => strtolower($id),
            default => $id,
        };
    }

    /** Lo que la tienda pone en la página: identificadores por herramienta (sin los vacíos). */
    public static function activas(array $config): array
    {
        $activas = [];
        foreach (array_keys(self::HERRAMIENTAS) as $clave) {
            if (filled($config[$clave] ?? null)) {
                $activas[substr($clave, strlen('medicion_'))] = $config[$clave];
            }
        }

        return $activas;
    }

    /**
     * Dominios que hay que permitir en la política de contenido de la tienda, según las herramientas
     * que tenga encendidas: ['script' => [...], 'conexion' => [...]].
     */
    public static function dominiosCsp(array $config): array
    {
        $dominios = ['script' => [], 'conexion' => []];
        foreach (self::HERRAMIENTAS as $clave => $h) {
            if (filled($config[$clave] ?? null)) {
                $dominios['script'] = [...$dominios['script'], ...$h['script']];
                $dominios['conexion'] = [...$dominios['conexion'], ...$h['conexion']];
            }
        }

        return array_map('array_unique', $dominios);
    }
}
