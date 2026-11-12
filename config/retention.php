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

    // Avisos de la campana (`notifications`) más antiguos que esto se BORRAN, aunque su ticket no se haya anonimizado
    // todavía (defecto D-5, decidido 2026-11-11). Mismo plazo que los registros: son su reflejo.
    // (No tiene variable propia a propósito: si el aviso vive más que el correo/auditoría que lo originó, sobra.)

    // Trabajos fallidos (`failed_jobs`): llevan el aviso serializado (ticket, asunto, empresa) y una traza. Se borran
    // pasados estos DÍAS; si algo no salió, interesa verlo pronto, no a los dos años.
    'failed_jobs_days' => (int) env('RETENTION_FAILED_JOBS_DAYS', 90),

    // Usuarios DADOS DE BAJA: pasado este plazo desde la baja se ANONIMIZAN (nombre, correo, teléfono, contraseña y
    // segundo factor); la fila y su id se conservan porque los tickets apuntan a ella (defecto D-6, decidido
    // 2026-11-11). Las EMPRESAS no se tocan: pueden pesar obligaciones contables y contractuales.
    'deactivated_users_years' => (int) env('RETENTION_DEACTIVATED_USERS_YEARS', 2),

    // Correo entrante (inbound_emails): los plazos de la cuarentena salen de config/inbound.php (spam 30 días, pendientes 90). Los correos ya
    // procesados (ticket, respuesta, ignorado, rechazado) solo guardan datos mínimos del remitente y se borran con el plazo de los pendientes.
];
