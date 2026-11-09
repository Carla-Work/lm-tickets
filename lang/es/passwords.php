<?php

/*
 * Mensajes de contraseña en español. Cuándo tocarlo: al cambiar un texto.
 * R17: `invalid_link` es el ÚNICO mensaje para cualquier fallo del enlace (caducado, ya usado, correo
 * que no coincide, cuenta de baja). No explicar cuál fue.
 */

return [
    'invalid_link' => 'El enlace no es válido o ha caducado. Pide uno nuevo desde «¿Olvidaste tu contraseña?» o solicita otra invitación.',
    'saved' => 'Contraseña guardada. Ya puedes iniciar sesión.',

    // Claves estándar de Laravel (por si algún código las usa directamente).
    'reset' => 'Tu contraseña se ha restablecido.',
    'sent' => 'Te hemos enviado el enlace por correo.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'El enlace no es válido o ha caducado.',
    'user' => 'No encontramos ninguna cuenta con ese correo.',
];
