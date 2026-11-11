# Producción: requisitos, configuración y despliegue

> Estado (2026-11-11): **preparado y probado en local; sin probar en el servidor real** (no hay hosting elegido todavía: IONOS está por
> decidir). Lo que depende del hosting está marcado ❓. Cuándo tocar este documento: al elegir hosting y al desplegar por primera vez
> (anotar lo que difiera). El plan de vuelta atrás está aparte: `docs/plan/vuelta-atras.md`.

## 1. Comprobar antes de abrir: `php artisan preflight:check`
Lee la configuración y el estado y dice qué falta; sale con código 1 si hay errores (sirve para cortar un despliegue). En local lo de
producción sale como AVISO; con `APP_ENV=production` o `--strict`, como ERROR. Comprueba: clave, base de datos (responde, en UTC,
migraciones al día), adjuntos privados (sin `public/storage`, R3), un único administrador, carpetas de adjuntos y copias, depuración,
HTTPS, registros con rotación y sin nivel debug, sesión cifrada y cookie segura, correo real, cola (que no sea `sync` y **que el
trabajador esté vivo**), copia reciente, segundo factor del administrador y **cuentas con la contraseña provisional**.
Sobre el entorno de desarrollo, hoy da 0 errores y 11 avisos (todo lo de producción); con `--strict`, 11 errores.

## 2. Requisitos del servidor
- **PHP 8.3** con las extensiones habituales de Laravel: `ctype, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre,
  phar, session, tokenizer, xml, xmlwriter` y `pdo_mysql`. Se ha comprobado con `composer check-platform-reqs`.
- **MySQL 8.0.16 o superior** (usa restricciones `CHECK` y una columna generada con índice único para «un solo administrador», R9).
  ⚠ **Solo se ha probado con MySQL 9.6** (la de desarrollo). **MariaDB NO está probado**: el `down()` de la migración de prioridad usa
  `ALTER TABLE … DROP CHECK` (sintaxis de MySQL). Si el hosting solo ofrece MariaDB, hay que probarla antes de comprometerse.
- **Composer 2** para instalar (`composer install --no-dev --optimize-autoloader`). **No hace falta Node** (el CSS se sirve tal cual).
- Para las copias: **`bash`, `gzip` y `mysqldump`** accesibles desde el usuario que ejecuta la aplicación y el cron.
- **Una entrada de cron** (ver 5) y **un trabajador de colas permanente** (ver 4).
- El **dominio** debe apuntar a `public/` (nunca a la raíz del proyecto: `.env`, `storage/` y el código no deben ser alcanzables).
- ❓ Hosting, versión de PHP/MySQL ofrecida por IONOS, si permite procesos permanentes (supervisor) o solo cron, y si hay proxy delante.

## 3. Configuración (`.env`)
Plantilla en `.env.production.example` (sin secretos). Valores que importan, con su razón:

| Variable | Valor | Por qué |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Con depuración, un error enseña rutas, consultas y variables. |
| `APP_KEY` | generar UNA vez, **no cambiar nunca** | Cifra las claves del segundo factor y las sesiones; cambiarla las invalida. Guardar copia fuera del servidor. |
| `APP_URL` | `https://…` | Los enlaces de los correos (invitación, recuperación) salen de aquí. |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` | Un único fichero crece sin límite; en `debug` guarda también datos de clientes. |
| `SESSION_ENCRYPT` / `SESSION_SECURE_COOKIE` | `true` / `true` | Sesión cifrada y cookie solo por HTTPS. |
| `TRUSTED_PROXIES` | vacío, IP/CIDR o `*` | Si un proxy termina el HTTPS: sin declararlo no hay HSTS y los enlaces salen con `http://` (ver `config/trustedproxy.php`). |
| `QUEUE_CONNECTION` | `database` | Los correos y avisos van por la cola. |
| `MAIL_*` | SMTP real | Con `log`, los correos solo se escriben en el registro. ❓ remitente y servidor. |
| `BACKUPS_KEEP_DAYS` / `_MONTHS` | `14` / `3` | Rotación de copias (ver 6). |
| `RETENTION_*` | 2 años / 2 / 90 días / 2 | Plazos provisionales de `retention:run`. |

Tras cambiar el `.env`: `php artisan config:cache`. ⚠ Con la configuración en caché el `.env` ya no se lee: cualquier cambio exige volver a
ejecutarlo. Y `env()` solo funciona dentro de `config/*.php` (en cualquier otro sitio devuelve nulo con la caché activa).

## 4. Trabajador de colas (defecto D-2)
Sin él **no sale ningún correo ni aviso** (invitaciones, recuperación de contraseña, avisos de tickets). Tiene que estar siempre en marcha
y reiniciarse si cae. Dos formas, según lo que permita el hosting ❓:
- **Con supervisor (recomendado)** — `/etc/supervisor/conf.d/lm-tickets-worker.conf`:
  ```ini
  [program:lm-tickets-worker]
  command=php /RUTA/lm-tickets/artisan queue:work --sleep=3 --tries=3 --max-time=3600
  directory=/RUTA/lm-tickets
  user=USUARIO_DE_LA_APLICACION
  autostart=true
  autorestart=true
  stopwaitsecs=3600
  stdout_logfile=/RUTA/lm-tickets/storage/logs/worker.log
  ```
  Después de cada despliegue: `php artisan queue:restart` (el trabajador carga el código al arrancar).
- **Solo con cron (si no hay procesos permanentes):** una línea que procese lo pendiente cada minuto:
  `* * * * * cd /RUTA/lm-tickets && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1`
  Los correos pueden tardar hasta un minuto.
- **Vigilarlo:** `preflight:check` avisa si hay trabajos esperando más de 10 minutos (= trabajador parado) y si hay trabajos fallidos. Convendría
  ejecutarlo periódicamente y que avise a una persona (❓ cómo: correo del propio cron, monitorización del hosting…). La pantalla
  Registros › Correos también enseña fallidos y sin confirmar.

## 5. Planificador (una entrada de cron)
`* * * * * cd /RUTA/lm-tickets && php artisan schedule:run >> /dev/null 2>&1`
Sin esa línea **no se hace ninguna copia ni rotación**. Lo programado (`routes/console.php`):
- 03:00 — copia de la base de datos (`scripts/db-backup.sh`, en el propio proceso, **no por la cola**: no debe depender del trabajador).
- 03:30 — rotación de copias (`backup:prune`).
- `retention:run` **NO está programado a propósito**: es irreversible y el plazo (2 años) está pendiente de validar con la asesoría.

## 6. Copias de seguridad
- Las hace el planificador en `storage/backups/` (ignorada por git; contiene datos de clientes). `php artisan backup:prune --dry-run` enseña qué
  rotaría. Rotación: todas las de 14 días + la más antigua de cada uno de los últimos 3 meses + la más reciente siempre.
- ⚠ **Una copia que no está fuera del servidor no sirve si el servidor se pierde.** Copiar `storage/backups/` a otro sitio (otro servidor, almacenamiento
  del hosting, y cifrada) es **decisión de la usuaria** ❓. Esa copia externa **también** conserva datos que `retention:run` ya borró: el plazo real de
  un dato «borrado» es el de la copia más antigua que lo lleve (hoy, unos 3 meses).
- ⚠ Los **adjuntos** (`storage/app/private/`) NO van en la copia de la base de datos: son ficheros y hay que copiarlos aparte (misma decisión ❓).
- **Restauración probada el 2026-11-11** (con el esquema actual): copia de `lm_tickets` → base temporal → 24 tablas con las mismas filas y *checksums*
  idénticos en `tickets`, `users`, `ticket_messages` y `audit_logs`. Repetirla en el servidor real antes de abrir:
  `bash scripts/db-restore.sh storage/backups/FICHERO.sql.gz BASE_TEMPORAL`.

## 7. Despliegue (primera vez y siguientes)
1. `bash scripts/db-backup.sh` y comprobar que la copia existe y pesa; **guardarla fuera**. Anotar el commit en producción (`git rev-parse HEAD`).
2. (Opcional, recomendable en un cambio grande) `php artisan down` para poner el modo mantenimiento.
3. Traer el código (`git pull` o subir el paquete) — **nunca** subir `.env`, `storage/` ni `vendor/` desde el portátil.
4. `composer install --no-dev --optimize-autoloader`
5. `php artisan migrate --force` (las migraciones nuevas; **el `down()` de algunas no es inocuo**: ver el plan de vuelta atrás).
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. `php artisan queue:restart` (y comprobar que el trabajador sigue vivo).
8. `php artisan preflight:check` → **0 errores** antes de abrir. Si falla, no se abre (el comando sale con código 1: `… preflight:check && php artisan up`).
9. `php artisan up`.
10. **Humo** (a mano, 5 minutos): entrar como cliente, abrir un ticket con adjunto, entrar como agente, responder, ver que llega el correo y el aviso, descargar el adjunto,
    cerrar sesión. Ver el administrador (Informes, Registros › Correos sin fallidos).

### Primera vez, además
- Crear la base de datos y el usuario de MySQL con los mínimos permisos (no usar `root`).
- `php artisan key:generate --force` (una vez) y guardar la clave.
- `php artisan migrate --force` y `php artisan db:seed --class=CatalogSeeder` (productos y categorías acordados).
- Crear el administrador: no hay registro libre (R18) y en producción NO se usa `tinker` con datos reales. ❓ Falta decidir cómo se crea la primera cuenta
  de administrador en el servidor (hoy se crea a mano en la base o con `admin:recover` sobre una cuenta existente). **Pendiente de decidir.**
- Cambiar la contraseña provisional de `comercial@lingmarco.es` (Contabilidad) y de cualquier cuenta de pruebas; **activar el 2FA del administrador**.
- **No** ejecutar `php artisan storage:link` (haría públicos los adjuntos, R3); `preflight:check` lo detecta.
- Permisos: el usuario de la aplicación escribe solo en `storage/` y `bootstrap/cache/`; nadie más. `public/` es lo único alcanzable desde fuera.

## 8. Después de desplegar
- Revisar cada día los primeros días: Registros › Correos (fallidos / sin confirmar), `storage/logs/`, `failed_jobs`, y que aparece la copia de la noche.
- `php artisan preflight:check` periódicamente (o en un cron que avise si sale con código 1).

## 9. Lo que NO está resuelto (decisiones y trabajo pendiente)
- ❓ **Hosting** (IONOS u otro), versión de MySQL, procesos permanentes, proxy, SMTP y remitente.
- ❓ **Copia externa** de la base y de los adjuntos, cifrada.
- ❓ **Primera cuenta de administrador** en el servidor.
- ❓ **Validar con la asesoría** los plazos de conservación y **programar `retention:run`**.
- ❓ **Aviso automático** si el trabajador de colas o la copia fallan (hoy solo lo detecta `preflight:check` cuando alguien lo ejecuta).
- Sin probar en el servidor real: nada de lo de este documento se ha ejecutado fuera de la máquina de desarrollo.
