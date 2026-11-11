# Plan de vuelta atrás

> Estado (2026-11-11): **escrito y con los tiempos medidos en local; sin ensayar en el servidor real.** La Fase 5 exige tenerlo escrito
> ANTES de desplegar: qué se revierte, cuánto tarda y qué datos se pierden. Cuándo tocar este documento: al elegir hosting (los tiempos
> cambian), al añadir migraciones con `down()` no inocuo y tras el primer ensayo real. Despliegue: `docs/plan/produccion.md`.

## Regla de oro
**Volver atrás = restaurar la copia de antes del despliegue y volver al código anterior. NO se usa `php artisan migrate:rollback` en
producción**: el `down()` de varias migraciones **borra tablas con datos reales** (ver la tabla de abajo). La copia de antes del despliegue
es lo que hace reversible todo.

## Antes de cada despliegue (sin esto no hay vuelta atrás)
1. `bash scripts/db-backup.sh` — comprobar que existe, que pesa y que `gzip -t` la da por buena. **Copiarla fuera del servidor.**
2. Anotar el commit actual (`git rev-parse HEAD`) y el último lote de migraciones (`php artisan migrate:status | tail`).
3. Copiar también `storage/app/private/` (adjuntos) si el cambio los toca: la copia de la base de datos NO los incluye.

## Cuándo volver atrás
Cualquiera de estas, tras desplegar: `preflight:check` con errores que no se arreglan en minutos; error 500 al entrar o al abrir un ticket; los correos
dejan de salir y no es el trabajador (`queue:restart`); un fallo de autorización (alguien ve lo que no debe: **inmediato**); datos que se ven mal tras una migración.
Si el problema es de configuración (`.env`), se arregla el `.env` y `config:cache`: no hace falta volver atrás.

## Cómo volver atrás
1. `php artisan down` (modo mantenimiento: nadie escribe mientras se restaura).
2. Código: `git checkout COMMIT_ANTERIOR` (o volver a subir el paquete anterior) y `composer install --no-dev --optimize-autoloader`.
3. Base de datos: restaurar la copia de antes del despliegue **en la misma base** (hay que forzar: `FORZAR=1 bash scripts/db-restore.sh COPIA.sql.gz BASE`;
   el script se niega a pisar la base en uso salvo con `FORZAR=1`, a propósito). Esto deja la base exactamente como estaba.
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart`.
5. `php artisan preflight:check` → 0 errores. `php artisan up`.
6. Humo de 5 minutos (entrar, abrir ticket, responder, correo) y avisar a quien use la aplicación.

## Cuánto tarda
Medido en local el 2026-11-11 con **20 000 tickets y 100 000 mensajes** (mucho más que el uso previsto): copia **< 1 s** (1,4 MB comprimida), restauración **2 s**.
Con el volumen previsto en los primeros meses es instantáneo. El tiempo real de la vuelta atrás lo dominan **las personas** (decidir, copiar el código, verificar):
**calcular 15-30 minutos** con la copia ya fuera del servidor. ❓ Los tiempos del hosting real están por medir (red, disco compartido).

## Qué datos se pierden
- **Todo lo ocurrido entre la copia y la restauración**: tickets, mensajes, valoraciones, cambios de estado y adjuntos subidos en ese intervalo. Por eso la copia se hace
  **justo antes** de desplegar y el modo mantenimiento se activa antes de restaurar. Si hubo actividad real tras el despliegue, **antes de restaurar**, hacer otra copia de la base
  «mala» (`db-backup.sh`) para poder rescatar a mano lo que se pueda (tickets nuevos, mensajes) y avisar a quienes lo escribieron.
- Los **adjuntos** subidos en ese intervalo siguen en disco (la restauración no los borra) pero quedan **sin fila** en la base: huérfanos. No dañan nada; se pueden borrar a mano.
- Los **correos ya enviados** no se pueden recoger: un cliente puede haber recibido un aviso de algo que tras la restauración «no existió».
- Las **sesiones** se restauran también: es posible que haya que volver a iniciar sesión.

## Qué hace cada migración al volver atrás con `migrate:rollback` (NO usarlo en producción)
| Migración | `down()` | Datos que destruye |
|---|---|---|
| `…_create_email_logs_table`, `…_create_audit_logs_table` | borra la tabla | **todo el registro de correos / la auditoría** |
| `…_add_anonymized_at_to_tickets_table`, `…_to_users_table` | quita la columna | la marca de «ya anonimizado» (el texto ya anonimizado NO se recupera) |
| `…_add_accounting_team` | pasa a los agentes de Contabilidad a Soporte y vuelve a la lista corta de equipos | quién era de Contabilidad |
| `…_add_priority_and_tags_to_tickets` | quita `priority` y borra `tags` y `ticket_tag` | **prioridades y etiquetas de todos los tickets** (sintaxis `DROP CHECK` solo de MySQL) |
| `…_create_ticket_ratings_table` | borra la tabla | **todas las valoraciones** |
Resumen: añadir una migración nueva es reversible **solo** restaurando la copia. Si una migración tiene que poder deshacerse sin perder datos, hay que escribirla así desde el principio.

## Lo que no se puede revertir (y conviene saberlo)
- **`retention:run`**: lo anonimizado y lo borrado no vuelve (solo desde una copia anterior). Es una razón más para no programarlo hasta validar los plazos.
- **Un cambio de `APP_KEY`**: invalida las claves del segundo factor y las sesiones. Si se cambió por error, volver a poner la clave anterior (por eso se guarda una copia fuera del servidor).
- **Una invitación o recuperación enviadas**: sus enlaces siguen válidos hasta caducar aunque se restaure la base (los tokens están en la base y vuelven a su estado).

## Ensayo
Antes del primer despliegue real, **ensayar esto una vez en el servidor**: copia → cambio pequeño → restaurar → comprobar. Anotar aquí la hora de cada paso y lo que falló.
