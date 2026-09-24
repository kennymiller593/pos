<?php

/*
 * wkhtmltopdf genera los tickets, los A4, los PDF de compras y los reportes.
 * Windows: WKHTML_PDF_BINARY="C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe"
 * Linux:   apt/dpkg del paquete "wkhtmltox" (version patched-qt) y WKHTML_PDF_BINARY=/usr/local/bin/wkhtmltopdf
 */
return [
    'pdf' => [
        'enabled' => true,
        'binary' => env('WKHTML_PDF_BINARY', '/usr/local/bin/wkhtmltopdf'),
        // un reporte grande no debe colgar un worker para siempre
        'timeout' => (int) env('WKHTML_TIMEOUT', 60),
        'options' => [],
        'env' => [],
    ],
    'image' => [
        'enabled' => false,
        'binary' => env('WKHTML_IMG_BINARY', '/usr/local/bin/wkhtmltoimage'),
        'timeout' => false,
        'options' => [],
        'env' => [],
    ],
];
