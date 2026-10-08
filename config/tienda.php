<?php

/*
 * Tienda en línea: el catálogo público de cada empresa vive en {slug}.{dominio}.
 *
 * El dominio sale de TIENDA_DOMINIO; si no está, se toma el de la app sin su primer nombre
 * (APP_URL=https://pos.inkanet.pro -> inkanet.pro). En local, "localhost": los navegadores
 * resuelven solos cualquier nombre.localhost a esta máquina.
 */

$hostApp = (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$partes = explode('.', $hostApp);
$porDefecto = filter_var($hostApp, FILTER_VALIDATE_IP) || count($partes) < 3
    ? ($hostApp === 'localhost' || filter_var($hostApp, FILTER_VALIDATE_IP) ? 'localhost' : $hostApp)
    : implode('.', array_slice($partes, 1));

return [
    'dominio' => env('TIENDA_DOMINIO', $porDefecto) ?: null,

    // host de la propia app: nunca es una tienda aunque comparta el dominio
    'host_app' => $hostApp,

    // Dominio propio (www.agrocampo.com). 'origen' es a donde apunta el CNAME del cliente
    // (tiendas.inkanet.pro, el "fallback origin" de Cloudflare for SaaS); 'dominios' es quien emite
    // el certificado: 'cloudflare', o 'simulado' en desarrollo (acepta todo sin DNS ni Cloudflare).
    // Sin ambos, la sección "Dominio" no se ofrece.
    // al guardar cambios se le pide a Cloudflare que olvide las páginas cacheadas de la tienda
    'purga' => (bool) env('TIENDA_PURGA_CACHE', true),

    'origen' => env('TIENDA_ORIGEN') ?: null,
    'dominios' => env('TIENDA_DOMINIOS') ?: null,

    // nombres que una empresa no puede usar como dirección (propios de la plataforma o genéricos)
    'reservados' => array_values(array_unique(array_filter(array_map('trim', [
        'www', 'pos', 'bot', 'api', 'app', 'admin', 'panel', 'img', 'cdn', 'static', 'assets', 'mail', 'smtp', 'ftp',
        'ns1', 'ns2', 'soporte', 'ayuda', 'blog', 'tienda', 'tiendas', 'inkapos', 'inkanet', 'login', 'registro',
        'test', 'dev', 'staging', 'status', 'tiendas',
        ...explode(',', (string) env('TIENDA_RESERVADOS', '')),
    ])))),

    // colores de marca que puede elegir cada tienda: [principal, al pasar el cursor, tinte suave]
    'colores' => [
        'esmeralda' => ['#059669', '#047857', '#ECFDF5'],
        'azul' => ['#2563EB', '#1D4ED8', '#EFF6FF'],
        'indigo' => ['#4F46E5', '#4338CA', '#EEF2FF'],
        'rojo' => ['#DC2626', '#B91C1C', '#FEF2F2'],
        'naranja' => ['#C2410C', '#9A3412', '#FFF7ED'],
        'grafito' => ['#1F2937', '#111827', '#F3F4F6'],
    ],
];
