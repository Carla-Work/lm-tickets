<?php

/*
 * Correo entrante: tickets creados a partir de correos (P1; plan docs/plan/p1-email-entrante.md). Cuándo tocarlo: al añadir un buzón,
 * cambiar un plazo o un límite. Las contraseñas de los buzones van en el `.env` (secretos: nunca en git).
 *
 * Trampas:
 *  - Cada buzón es COMPARTIDO con personas que lo leen a la vez: la lectura NO marca como leído, no borra y no mueve nada
 *    (ver ImapMailboxClient). Qué correos ya se procesaron se apunta en la base (`inbound_emails`), no en el buzón.
 *  - El DEPARTAMENTO sale del buzón (no del contenido): aplicaciones@ → web_apps, soporte@ → support, comercial@ → other
 *    (Contabilidad, R13). Valores de App\Enums\Department.
 *  - `INBOUND_ENABLED` empieza en falso: sin él ni se programa la lectura ni se conecta a nada.
 */

return [
    'enabled' => (bool) env('INBOUND_ENABLED', false),

    // Un correo solo crea ticket o entra en un hilo si el servidor que lo recibió dice que SPF o DKIM pasaron (el «De» se falsifica).
    // Se puede relajar solo para pruebas; en producción debe seguir en true. `preflight:check` lo avisa.
    'require_auth' => (bool) env('INBOUND_REQUIRE_AUTH', true),

    // Cada ejecución mira los correos de los últimos N días (el servidor no permite filtrar por «no procesado» sin tocar los
    // indicadores del buzón). Los ya procesados se saltan por su Message-ID.
    'lookback_days' => (int) env('INBOUND_LOOKBACK_DAYS', 3),

    // Tope del correo SALIENTE con adjuntos (decidido por la usuaria, 2026-11-12): por encima, el servidor del cliente puede rechazarlo.
    'max_outgoing_mb' => (int) env('INBOUND_MAX_OUTGOING_MB', 10),

    // Plazos de la cuarentena (decididos por la usuaria, 2026-11-12). Se cuentan desde que llegó el correo.
    'spam_days' => (int) env('INBOUND_SPAM_DAYS', 30),
    'pending_days' => (int) env('INBOUND_PENDING_DAYS', 90),

    // Categoría con la que nace un ticket que llega por correo (el agente la corrige después, R12). Una por departamento.
    'default_category' => 'Sin clasificar',

    // Dominios de correo gratuito: NUNCA pueden registrarse como dominio de una empresa (cualquiera tiene uno).
    'free_domains' => [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com', 'yahoo.com', 'yahoo.es',
        'icloud.com', 'me.com', 'msn.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.es', 'terra.es', 'telefonica.net',
        'movistar.es', 'orange.es', 'ono.com', 'wanadoo.es', 'mail.com', 'yandex.com', 'zoho.com',
    ],

    // Dominio del propio equipo: un correo de aquí no es de un cliente (compañeros, avisos del sistema) y se ignora.
    'internal_domain' => env('INBOUND_INTERNAL_DOMAIN', 'lingmarco.es'),

    'mailboxes' => [
        [
            'key' => 'apps',
            'address' => env('INBOUND_APPS_ADDRESS', 'aplicaciones@lingmarco.es'),
            'department' => 'web_apps',
            'host' => env('INBOUND_APPS_HOST'),
            'port' => (int) env('INBOUND_APPS_PORT', 993),
            'encryption' => env('INBOUND_APPS_ENCRYPTION', 'ssl'),
            'username' => env('INBOUND_APPS_USERNAME'),
            'password' => env('INBOUND_APPS_PASSWORD'),
            'folder' => env('INBOUND_APPS_FOLDER', 'INBOX'),
        ],
        [
            'key' => 'support',
            'address' => env('INBOUND_SUPPORT_ADDRESS', 'soporte@lingmarco.es'),
            'department' => 'support',
            'host' => env('INBOUND_SUPPORT_HOST'),
            'port' => (int) env('INBOUND_SUPPORT_PORT', 993),
            'encryption' => env('INBOUND_SUPPORT_ENCRYPTION', 'ssl'),
            'username' => env('INBOUND_SUPPORT_USERNAME'),
            'password' => env('INBOUND_SUPPORT_PASSWORD'),
            'folder' => env('INBOUND_SUPPORT_FOLDER', 'INBOX'),
        ],
        [
            'key' => 'accounting',
            'address' => env('INBOUND_ACCOUNTING_ADDRESS', 'comercial@lingmarco.es'),
            'department' => 'other',
            'host' => env('INBOUND_ACCOUNTING_HOST'),
            'port' => (int) env('INBOUND_ACCOUNTING_PORT', 993),
            'encryption' => env('INBOUND_ACCOUNTING_ENCRYPTION', 'ssl'),
            'username' => env('INBOUND_ACCOUNTING_USERNAME'),
            'password' => env('INBOUND_ACCOUNTING_PASSWORD'),
            'folder' => env('INBOUND_ACCOUNTING_FOLDER', 'INBOX'),
        ],
    ],
];
