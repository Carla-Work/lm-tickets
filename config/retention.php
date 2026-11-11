<?php

/*
 * Plazos de conservación de datos (plan: docs/plan/conservacion-datos.md). Cuándo tocarlo: al cambiar un plazo.
 * Se cambia aquí o con la variable de entorno; NO hace falta tocar la lógica de `php artisan retention:run`.
 *
 * Estado: PROVISIONAL. La usuaria fijó 2 años el 2026-11-09; el encuadre pide validarlo con la asesoría legal antes
 * de producción. Los plazos están en AÑOS completos y se cuentan hacia atrás desde el momento de ejecutar.
 */

return [
    // Tickets CERRADOS: desde la fecha de cierre se anonimizan (asunto, mensajes, notas, solución) y se borran sus
    // adjuntos. El ticket, su historial de estados y sus datos de clasificación se conservan (estadística).
    'tickets_years' => (int) env('RETENTION_TICKETS_YEARS', 2),

    // Registro de correos (`email_logs`) y auditoría (`audit_logs`): se BORRAN las filas más antiguas que esto.
    'logs_years' => (int) env('RETENTION_LOGS_YEARS', 2),
];
