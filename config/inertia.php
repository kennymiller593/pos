<?php

return [

    /*
    | Las paginas viven en resources/js/Pages (P mayuscula). El valor por defecto
    | del paquete es js/pages: en Windows da igual, en Linux (CI y servidor) no.
    */
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/Pages'),
        ],
        'extensions' => ['vue'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

];
