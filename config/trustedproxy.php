<?php

/*
 * Proxies de confianza: quién puede decirnos «esta petición llegó por HTTPS» con las cabeceras X-Forwarded-*. Cuándo tocarlo: al
 * cambiar de hosting. Guía: docs/plan/produccion.md.
 *
 * Por qué importa: en hosting, un proxy o balanceador suele terminar el HTTPS y hablar con la aplicación por HTTP. Si la aplicación no
 * confía en él, `isSecure()` es falso: no se envía HSTS (SecurityHeaders) y los enlaces y activos se generan con http:// (contenido
 * mezclado, el navegador los bloquea). Si confía en quien no debe, cualquiera puede falsificar la cabecera.
 *
 * Trampa (comprobada el 2026-11-11 con el servidor real): esto va en un FICHERO DE CONFIGURACIÓN, no en `bootstrap/app.php`. Allí
 * `env()` se ejecuta antes de cargar el `.env` en una petición web y devuelve nulo (con la variable solo en `.env`, el proxy se
 * ignoraba sin avisar). Laravel lee esta clave en cada petición (Illuminate\Http\Middleware\TrustProxies).
 *
 * TRUSTED_PROXIES: vacío = ningún proxy (lo seguro por defecto); «*» = el que llame (SOLO si la aplicación no es alcanzable sin pasar
 * por el proxy); o una lista de IP/CIDR separadas por comas (p. ej. «10.0.0.0/8, 192.168.1.1»).
 */

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
