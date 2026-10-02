<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // copia de seguridad fuera del servidor (Cloudflare R2 u otro compatible con S3): ver respaldo:nube
        'respaldo' => [
            'driver' => 's3',
            'key' => env('RESPALDO_ACCESS_KEY_ID'),
            'secret' => env('RESPALDO_SECRET_ACCESS_KEY'),
            'region' => env('RESPALDO_REGION', 'auto'),
            'bucket' => env('RESPALDO_BUCKET'),
            'endpoint' => env('RESPALDO_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],

        // fotos de productos: bucket publico (Cloudflare R2 con dominio propio) si IMAGENES_BUCKET
        // esta definido; si no, la carpeta publica del servidor, como siempre
        'imagenes' => env('IMAGENES_BUCKET') ? [
            'driver' => 's3',
            'key' => env('IMAGENES_ACCESS_KEY_ID', env('RESPALDO_ACCESS_KEY_ID')),
            'secret' => env('IMAGENES_SECRET_ACCESS_KEY', env('RESPALDO_SECRET_ACCESS_KEY')),
            'region' => env('IMAGENES_REGION', 'auto'),
            'bucket' => env('IMAGENES_BUCKET'),
            'endpoint' => env('IMAGENES_ENDPOINT', env('RESPALDO_ENDPOINT')),
            // direccion publica del bucket, p. ej. https://img.inkanet.pro
            'url' => env('IMAGENES_URL'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
