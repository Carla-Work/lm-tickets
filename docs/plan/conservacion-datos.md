# Plan de conservación de datos

Estado: **implementado y PROVISIONAL** (2026-11-10). Plazo de **2 años** (usuaria, 2026-11-09). Las decisiones de
abajo las tomó la usuaria el 2026-11-10; el comando está hecho y probado pero **NO está programado**: se ejecuta a
mano hasta que el plazo se valide con la asesoría legal (no es asesoramiento legal). Antes de producción: validar.

## Qué datos hay y qué pide decidir cada uno

| Dato | Provisional | Opciones a decidir |
|---|---|---|
| Tickets cerrados (asunto, hilo, notas internas) | 2 años desde el cierre | Borrar · anonimizar (conservar para estadística sin datos personales) |
| Adjuntos | 2 años desde el cierre del ticket | Borrar antes que el ticket (suelen ser lo más sensible y ocupan más) |
| Usuarios de empresa dados de baja | 2 años desde la baja | Anonimizar para conservar el historial del ticket sin identificar a la persona |
| Empresa cliente tras terminar la relación | 2 años | Depende de obligaciones contables/contractuales ❓ |
| Registro de actividad (cambios de estado, accesos) | 2 años | Puede necesitar menos o más según su uso |
| Registro de correos (`email_logs`: direcciones y asuntos; cuerpo solo de los avisos de tickets) | 2 años (provisional, igual que el registro de actividad) | **Purga programada PENDIENTE**: hoy no se borra nada |
| Auditoría (`audit_logs`: accesos con IP, invitaciones, 2FA, copias) | 2 años (provisional) | **Purga programada PENDIENTE**; la tabla solo se añade, la purga será un comando propio |
| Copias de seguridad | ❓ | Las copias contienen datos ya "borrados": su rotación fija el plazo real |

## Lo decidido (2026-11-10) y lo implementado

| Dato | Plazo | Qué se hace | Estado |
|---|---|---|---|
| Tickets **cerrados** | 2 años **desde el cierre** | **Anonimizar**: asunto → «Ticket anonimizado», solución vacía, TODOS los mensajes (también notas internas) → texto fijo. Se conservan el ticket, quién lo abrió, su clasificación y su historial de estados | **Hecho** (`retention:run`) |
| Adjuntos de esos tickets | con el ticket | **Borrar** fichero y fila | **Hecho** |
| Avisos de la campana de esos tickets | con el ticket | Borrar (llevan asunto y empresa) | **Hecho** |
| Registro de correos (`email_logs`) | 2 años | Borrar filas antiguas | **Hecho** |
| Auditoría (`audit_logs`) | 2 años | Borrar filas antiguas (esta purga es el ÚNICO sitio que borra auditoría); deja su propia fila con los recuentos | **Hecho** |
| Enlaces de contraseña caducados (`password_reset_tokens`) | más viejos que el plazo del más largo (invitación, 7 días) | Borrar (llevan el correo y Laravel solo los borra al usarlos). Decisión técnica de Claude, sin dato personal de valor | **Hecho** (2026-11-11, defecto D-5) |
| Aviso previo al cliente | — | **Ninguno** (decidido) | — |
| Usuarios dados de baja | ❓ | Anonimizar (propuesta) | **SIN DECIDIR, no implementado** |
| Empresa tras terminar la relación | ❓ | Depende de obligaciones contables/contractuales | **SIN DECIDIR, no implementado** |
| Copias de seguridad | ❓ | Su rotación fija el plazo real de los datos «borrados» | **SIN DECIDIR** |

## Cómo se usa

```bash
php artisan retention:run --dry-run   # enseña qué afectaría, sin cambiar nada
php artisan retention:run             # lo aplica (IRREVERSIBLE salvo desde una copia de seguridad)
```

- Plazos en `config/retention.php` (o `RETENTION_TICKETS_YEARS` / `RETENTION_LOGS_YEARS` en `.env`): se cambian sin tocar la lógica.
- **No está programado** (decisión de la usuaria hasta validar el plazo). Para programarlo cuando se dé el visto
  bueno: `Schedule::command('retention:run')->daily()` en `routes/console.php` y el planificador de Laravel en el servidor.
- Se puede repetir sin riesgo: lo ya anonimizado se salta (marca `tickets.anonymized_at`).
- Cada ticket va en su transacción; los ficheros se borran después de confirmarla.
- Tests: `tests/Feature/Console/RetentionTest.php` (plazos, dry-run, ficheros, notas internas, idempotencia, logs).

## Limitaciones conocidas

- Un ticket **Resuelto que nadie cierra** no caduca nunca (el plazo cuenta desde el cierre). Conviene vigilar los resueltos antiguos.
- Las personas siguen existiendo: el ticket anonimizado conserva `requester_id`, y su nombre sale en las pantallas de ese
  ticket. Hasta decidir qué hacer con usuarios de baja, el historial de un ticket anonimizado sigue diciendo QUIÉN lo abrió
  (no QUÉ decía).
- Las copias de seguridad contienen los datos antes de la purga.

## Decisiones abiertas

0. Sin plazo todavía: avisos de la campana antiguos de tickets no anonimizados (`notifications`), trabajos fallidos (`failed_jobs`) y sesiones (`sessions`; caducan solas pero no se purgan). Ver D-5 en `docs/defectos-conocidos.md`.

1. Usuarios dados de baja: ¿anonimizar a los 2 años de la baja? (Propuesta: sí.)
2. Empresa cliente: plazo y obligaciones contables/contractuales (¿asesoría?).
3. Obligaciones legales que obliguen a conservar más (un ticket que respalda una factura o un servicio).
4. Atención de derechos RGPD (acceso, rectificación, supresión): quién, en qué plazo y cómo se registra.
5. Rotación de copias de seguridad para que el borrado también llegue a ellas.
6. Validar los plazos con la asesoría legal y programar el comando.
