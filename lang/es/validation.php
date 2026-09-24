<?php

/*
 * Mensajes de validación en español. Los formularios de la app traen sus
 * propios mensajes; aquí van los de las reglas que no los admiten (política
 * de contraseñas) y los más comunes por si alguna regla se queda sin mensaje.
 */
return [
    'accepted' => 'Debes aceptar :attribute.',
    'after_or_equal' => ':attribute debe ser una fecha igual o posterior a :date.',
    'array' => ':attribute debe ser una lista.',
    'boolean' => ':attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'date' => ':attribute no es una fecha válida.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'email' => ':attribute debe ser un correo válido.',
    'exists' => 'El valor elegido para :attribute no es válido.',
    'gt' => ['numeric' => ':attribute debe ser mayor que :value.'],
    'image' => ':attribute debe ser una imagen.',
    'in' => 'El valor elegido para :attribute no es válido.',
    'max' => [
        'numeric' => ':attribute no debe ser mayor que :max.',
        'string' => ':attribute no debe superar :max caracteres.',
        'file' => ':attribute no debe pesar más de :max kilobytes.',
    ],
    'mimes' => ':attribute debe ser un archivo de tipo :values.',
    'min' => [
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
        'array' => ':attribute debe tener al menos :min elementos.',
    ],
    'numeric' => ':attribute debe ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'string' => ':attribute debe ser texto.',
    'unique' => ':attribute ya está en uso.',
    'uuid' => ':attribute no es válido.',

    'password' => [
        'letters' => 'La contraseña debe incluir al menos una letra.',
        'mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
        'numbers' => 'La contraseña debe incluir al menos un número.',
        'symbols' => 'La contraseña debe incluir al menos un símbolo.',
        'uncompromised' => 'Esa contraseña apareció en filtraciones de datos. Elige otra distinta.',
    ],

    'attributes' => [
        'email' => 'correo',
        'password' => 'contraseña',
        'nombre_completo' => 'nombre',
        'razon_social' => 'razón social',
        'ruc' => 'RUC',
        'numero_documento' => 'número de documento',
        'motivo' => 'motivo',
        'monto' => 'monto',
        'cantidad' => 'cantidad',
        'desde' => 'fecha inicial',
        'hasta' => 'fecha final',
    ],
];
