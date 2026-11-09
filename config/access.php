<?php

/*
 * Reglas de acceso de la aplicación. Qué es: los números de R17-R21 en un solo sitio.
 * Cuándo tocarlo: al decidir con la usuaria otros plazos o límites.
 *
 * Dónde están los demás: la caducidad de los enlaces (60 min el de contraseña, 7 días el de
 * invitación) vive en config/auth.php, en los «passwords brokers» de Laravel.
 *
 * Valores decididos por la usuaria el 2026-11-09: 5 intentos, mínimo 12 caracteres, enlace de 60 min.
 * Valores elegidos por Claude y pendientes de validar: 15 minutos de bloqueo y 7 días de invitación.
 */

return [
    'login' => [
        // R20: tras este número de fallos seguidos se bloquea el acceso.
        'max_attempts' => 5,
        // Cuánto dura el bloqueo (y la ventana en la que se cuentan los fallos). No decidido: propuesta.
        'lockout_seconds' => 15 * 60,
    ],

    'password' => [
        // Longitud mínima. Sin más exigencias (mayúsculas, símbolos): se prefiere una frase larga.
        'min_length' => 12,
    ],
];
