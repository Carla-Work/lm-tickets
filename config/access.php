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

    // R3 y adjuntos. Valores propuestos por Claude y aprobados por la usuaria el 2026-11-10.
    // El encuadre pide que tipos y tamaño sean configurables: se cambian aquí, no en el código.
    'attachments' => [
        'max_files' => 5,          // archivos por mensaje
        'max_kb' => 10 * 1024,     // por archivo (10 MB, como dicen los mockups)
        // Suma máxima por envío. Debe quedar POR DEBAJO de post_max_size de PHP (32M aquí): si PHP lo supera,
        // descarta toda la petición sin avisar y el usuario ve un error 419 sin explicación.
        'max_total_kb' => 25 * 1024,
        // Lista CERRADA: extensión → tipos MIME que se aceptan para esa extensión, mirando el CONTENIDO real del
        // archivo (finfo), no lo que diga el navegador. Quedan fuera a propósito ejecutables, scripts y lo que un
        // navegador interpreta como página (php, exe, html, js, svg…). Los OOXML (docx/xlsx) son ZIP por dentro y
        // según la versión de libmagic se detectan como uno u otro: se aceptan ambos.
        'types' => [
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'zip' => ['application/zip'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
        ],
    ],

    'password' => [
        // Longitud mínima. Sin más exigencias (mayúsculas, símbolos): se prefiere una frase larga.
        'min_length' => 12,
    ],

    // Sección «Registros» del administrador (correos, auditoría, copias).
    'records' => [
        // Dónde deja scripts/db-backup.sh las copias (contienen datos de clientes: fuera de public/ y de git).
        'backups_path' => storage_path('backups'),
        // Filas por página en correos y auditoría.
        'per_page' => 15,
    ],
];
