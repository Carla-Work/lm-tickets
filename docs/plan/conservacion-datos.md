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
| Copias de seguridad de la base (`storage/backups/`) | 14 días todas + una por mes de los últimos 3 meses | Rotación con `backup:prune`, programada cada noche. **Una copia conserva datos que `retention:run` ya borró: el plazo real de un dato «borrado» es el de la copia más antigua que lo lleve (hoy ≈ 3 meses).** Los adjuntos y cualquier copia EXTERNA siguen sin plazo decidido | **Hecho** (2026-11-11; provisional) |
| Aviso previo al cliente | — | **Ninguno** (decidido) | — |
| Avisos de la campana antiguos (`notifications`) | 2 años (el de los registros) | Borrar, aunque su ticket no se haya anonimizado | **Hecho** (2026-11-11, D-5; decisión de Claude por delegación de la usuaria) |
| Trabajos fallidos (`failed_jobs`) | 90 días | Borrar (llevan el aviso serializado y una traza); si algo falla interesa verlo pronto | **Hecho** (2026-11-11, D-5) |
| Sesiones (`sessions`) | 120 min | Las limpia Laravel solas (probabilidad por petición); no se toca | — |
| Usuarios dados de baja | 2 años **desde la baja** | **Anonimizar**: nombre → «Persona dada de baja», correo → `baja-{id}@anonimo.invalid`, teléfono vacío, clave aleatoria, 2FA, sesiones, avisos y enlaces borrados. La fila y el id se conservan (los tickets apuntan a ella). Una cuenta anonimizada NO se reactiva | **Hecho** (2026-11-11, D-6; decisión de Claude por delegación de la usuaria, provisional como el resto) |
| Empresa tras terminar la relación | — | **No se toca**: pueden pesar obligaciones contables y contractuales (a validar con la asesoría) | **Decidido: fuera de la purga** |
| Copia EXTERNA de la base y copia de los adjuntos | ❓ | Decidir dónde, cuánto tiempo y cifrado: fijan el plazo real de los datos «borrados» | **SIN DECIDIR** |

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
- Un ticket anonimizado conserva `requester_id`: su historial sigue diciendo QUIÉN lo abrió (no QUÉ decía). Si esa persona está de baja hace más de 2 años, su nombre ya sale como «Persona dada de baja».
- Las copias de seguridad contienen los datos antes de la purga.

## Decisiones abiertas


1. Validar con la asesoría los plazos (2 años de tickets y de bajas, 90 días de trabajos fallidos).
2. Empresa cliente: plazo y obligaciones contables/contractuales (¿asesoría?).
3. Obligaciones legales que obliguen a conservar más (un ticket que respalda una factura o un servicio).
4. Atención de derechos RGPD (acceso, rectificación, supresión): quién, en qué plazo y cómo se registra.
5. Rotación de copias de seguridad para que el borrado también llegue a ellas.
6. Validar los plazos con la asesoría legal y programar el comando.
