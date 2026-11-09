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

    // R21: segundo factor del administrador (TOTP, RFC 6238). Sin código QR ni librerías.
    'two_factor' => [
        'issuer' => 'Ling Marco Tickets', // el nombre que ve la persona en su app de autenticación
        'digits' => 6,
        'period' => 30,                   // segundos de vida de cada código
        'window' => 1,                    // intervalos tolerados a cada lado por desfase del reloj
        'recovery_codes' => 8,            // cuántos códigos de recuperación se entregan
        // Tiempo máximo entre acertar la contraseña y completar el segundo factor. Propuesta de Claude.
        'pending_seconds' => 10 * 60,
    ],

    'password' => [
        // Longitud mínima. Sin más exigencias (mayúsculas, símbolos): se prefiere una frase larga.
        'min_length' => 12,
    ],
];
