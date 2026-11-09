<?php

/*
 * Mensajes de validación en español. Cuándo tocarlo: al usar una regla de validación nueva que no
 * esté aquí (sin traducción saldría la clave en crudo, p. ej. «validation.required»).
 * Se traducen solo las reglas que usa la aplicación; amplía la lista al necesitar otra.
 */

return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'Escribe un correo electrónico válido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'string' => 'El campo :attribute debe ser texto.',
    'unique' => 'Ya existe un registro con ese :attribute.',
    'exists' => 'El valor elegido para :attribute no es válido.',
    'in' => 'El valor elegido para :attribute no es válido.',
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El archivo :attribute debe pesar al menos :min KB.',
    ],
    'max' => [
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El archivo :attribute no puede pesar más de :max KB.',
    ],
    'attributes' => [
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de la contraseña',
        'name' => 'nombre',
        'token' => 'enlace',
    ],
];
