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

## 2b. Web pública en WordPress (decidido 2026-11-11)
La web de Ling Marco es **WordPress** y la aplicación de tickets sigue siendo **Laravel**, como dos sitios separados:

| | Web pública | Aplicación de tickets |
|---|---|---|
| Qué es | WordPress (`lingmarco.es`) | Esta aplicación, en un **subdominio propio** (**`tickets.lingmarco.es`**, decidido 2026-11-11) |
| Base de datos | La suya | La suya, con **otro usuario y otra contraseña**; WordPress nunca lee la base de la aplicación ni al revés |
| Sesión | La de WordPress | La de la aplicación. **No comparten sesión ni hay acceso único (SSO)**: el cliente entra con su cuenta de invitación (R18) en el subdominio |
| Enlace entre ambas | Botón «Acceso clientes» → `https://tickets.lingmarco.es/acceso` | Sin enlace obligatorio de vuelta |

**Por qué así y no de otra forma**
- **Subdominio y no subcarpeta** (`lingmarco.es/tickets`): WordPress y Laravel se pisarían el `.htaccess` y las rutas, y esta aplicación **no se ha probado con un prefijo** (todas sus rutas y recursos asumen la raíz del dominio). Lo de `publico.lingmarco.com/lingmarco/` es solo el entorno de diseño.
- **Sin iframe**: la cookie de sesión entre dominios, las cabeceras de seguridad de la Fase 5 y la accesibilidad se complican, y habría que relajar protecciones que hoy están puestas.
- **Sin plugin ni reescritura en WordPress**: se tiraría el trabajo hecho (reglas R1–R21 con test) y se reabriría el stack.

**Qué hay que hacer en el servidor** (nada de esto está hecho; ver § 9)
1. Crear el subdominio en el DNS apuntando al hosting y darle su **certificado HTTPS** propio.
2. Su raíz de documentos debe ser `public/` de esta aplicación (§ 2), no la carpeta de WordPress.
3. `APP_URL=https://tickets.lingmarco.es` (los enlaces de los correos salen de aquí) y `SESSION_DOMAIN` **vacío** (cookie solo de ese host: la cookie de la aplicación no debe viajar a WordPress ni al revés).
4. En WordPress, un enlace o botón a `https://tickets.lingmarco.es/acceso`. Ejemplo (colores de marca; el morado sobre blanco da contraste 7,5:1):
   `<a href="https://tickets.lingmarco.es/acceso" style="background:#742bba;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;font-weight:600">Acceso clientes</a>`
5. El remitente de los correos (§ 9) debe ser de un dominio con SPF/DKIM, también si es distinto del subdominio.

**Decidido 2026-11-11 (la usuaria):** el subdominio es `tickets.lingmarco.es`; el WordPress está en el **mismo hosting de IONOS**; el **DNS lo gestiona IONOS** (el subdominio, el registro y el certificado se crean desde su panel, sin terceros).

**Qué sigue dependiendo de IONOS y NO está verificado** (no lo doy por hecho; hay que mirarlo en el contrato/panel de IONOS antes de desplegar):
- Si el plan permite **un subdominio con su propia carpeta raíz** (aquí, la carpeta `public/` de esta aplicación) y un certificado HTTPS para él.
- La versión de **PHP** (mínimo 8.3) y de **MySQL** (mínimo 8.0.16, ver § 2) que ofrece; y si da una **segunda base de datos** para la aplicación, separada de la de WordPress.
- Si permite **SSH y Composer** (si no, hay que subir `vendor/` ya instalado desde otra máquina) y **`mysqldump` y `gzip`** para las copias.
- Si permite un **proceso permanente** para la cola o solo **cron** (ver § 4: en alojamiento compartido lo habitual es solo cron).
- Qué **SMTP** da IONOS y qué remitente puede usar (ver § 9).

**Pendiente de decidir** (la usuaria): si el subdominio debe quedar **fuera de los buscadores** (hoy `public/robots.txt` lo permite todo, solo la pantalla de acceso es pública; lo razonable sería `Disallow: /`, no cambiado porque es decisión suya) y si quiere un enlace «volver a la web» en la pantalla de acceso (llevaría mockup).

## 2b-bis. Dónde se sube (FTP, IONOS)
Carpeta de destino indicada por la usuaria (2026-11-11): **`/clickandbuilds/tickets`**. Ahí se sube el CONTENIDO de `aplicacion/` (paquete de `lm-tickets-despliegue/`), de modo que existan `/clickandbuilds/tickets/public/index.php`, `/clickandbuilds/tickets/.env` (lo creas tú) y `/clickandbuilds/tickets/vendor/`.
**Lo que decide si funciona:** que el subdominio `tickets.lingmarco.es` esté configurado en el panel de IONOS con destino **`/clickandbuilds/tickets/public`** (no `/clickandbuilds/tickets`). Si el destino fuera la raíz de la aplicación, `.env`, `storage/` y el código serían alcanzables desde internet. ❓ No verificado: si el panel admite ese destino con subcarpeta `public`.

**`.htaccess` de IONOS (comprobado 2026-11-11):** el de Laravel por defecto daba 500 de Apache a toda petición en `tickets.lingmarco.es`. `public/.htaccess` es ahora una versión mínima con `RewriteBase /` (la forma del WordPress de ese servidor). Si tras subir la aplicación sale un 500 de Apache y no la página de error en español, es lo primero que se mira. Su cabecera explica qué se quitó y qué se pierde.

## 2c. Crear la base de datos en IONOS
El SQL lo genera `bash scripts/db-export-schema.sh` → `storage/exports/lm_tickets_esquema_FECHA.sql` (ignorado por git; se regenera cuando cambie el esquema).
Lleva las 24 tablas, la tabla `migrations` rellena y el catálogo inicial (productos y categorías); **no lleva empresas, usuarios, tickets ni ningún dato de clientes**.
Probado en local (2026-11-11): importado en una base vacía, `php artisan migrate:status` no ve nada pendiente y se mantienen el índice de «un solo administrador» (R9) y las restricciones `CHECK`.

1. En el panel de IONOS, **crear una base de datos MySQL nueva y vacía** para la aplicación (distinta de la de WordPress). IONOS asigna el nombre, el servidor, el usuario y la contraseña: apúntalos. El fichero **no** crea la base ni usa `USE`.
2. **Importar** el `.sql` dentro de esa base (phpMyAdmin → Importar, o `mysql -h SERVIDOR -u USUARIO -p BASE < fichero.sql` si hay SSH). Pesa ~32 KB.
3. Poner en el `.env` del servidor `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` con esos valores (§ 3). Nunca al repositorio.
4. En el servidor, `php artisan migrate:status`: tiene que decir que no hay nada pendiente. Si lista migraciones pendientes, el SQL es de una versión anterior: regenerarlo.
5. **Alta del equipo de Ling Marco (sin SSH ni correo):** `bash scripts/db-export-staff.sh` genera, desde la base local, `storage/exports/lm_tickets_usuarios_ling_marco_FECHA.sql` y `storage/exports/enlaces-invitacion_FECHA.txt`.
   Importar el `.sql` **después** del de esquema, en la misma base. Cada persona (administrador y agentes activos con correo `@lingmarco.es`, 7 el 2026-11-11) nace como una **invitación sin aceptar** (R18): sin contraseña conocida y con un token cifrado que caduca a los **7 días de generar el SQL**. El `.txt` trae un enlace por persona (`https://tickets.lingmarco.es/invitacion/…`): son **llaves**, se entregan por separado y se borran.
   **No copia contraseñas locales** (las de desarrollo son conocidas), ni cuentas de baja o de prueba, ni clientes. Probado con la aplicación real sobre una base limpia: el administrador acepta el enlace, crea su contraseña y entra; el enlace no sirve dos veces; un agente sin aceptar no entra; un agente y Contabilidad aceptan y entran en su bandeja.
   ⚠ Si la base de destino ya tiene usuarios, no importarlo. Para añadir más gente después, usar «Administración › Usuarios» (necesita cola y correo en marcha, § 4) o regenerar el SQL.

⚠ Probado solo con **MySQL 9.6**; las tablas salen con la colación `utf8mb4_unicode_ci`. **MariaDB no está probado** y probablemente falle (columna generada `users.admin_slot` y `CHECK` con sintaxis de MySQL 8). Ver la versión que ofrece IONOS antes de importar.

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

### Correo entrante (P1, 2026-11-12; plan `docs/plan/p1-email-entrante.md`)
Desactivado por defecto (`INBOUND_ENABLED=false`). Para activarlo:
1. **Buzones IMAP** (`aplicaciones@`, `soporte@`, `comercial@`): `INBOUND_APPS_*`, `INBOUND_SUPPORT_*`, `INBOUND_ACCOUNTING_*` (`HOST`, `PORT`, `ENCRYPTION`, `USERNAME`, `PASSWORD`; ver `config/inbound.php`). Contraseñas SOLO en el `.env`.
   Los buzones son compartidos con personas: la lectura **no marca como leído, no borra ni mueve nada**.
2. **SPF/DKIM**: el filtro exige que el servidor que recibe el correo añada la cabecera `Authentication-Results` con `spf=pass` o `dkim=pass`. ❓ **Comprobar que IONOS la añade**: si no,
   TODO caerá en la cuarentena (se ve enseguida en «Correo entrante» con el motivo «el servidor no aportó comprobación»). `INBOUND_REQUIRE_AUTH=false` solo para pruebas (`preflight:check` lo marca error en producción).
3. **Envío**: las respuestas salen desde la dirección del buzón del departamento (aplicaciones@, soporte@, comercial@): el SMTP debe permitir enviar con esas direcciones. ❓ por comprobar.
4. Extensiones de PHP: `zip`, `iconv`, `mbstring`, `fileinfo`, `openssl` (`preflight:check` las comprueba si está activado). ❓ `zip` en IONOS por comprobar.
5. Con `INBOUND_ENABLED=true`, `routes/console.php` programa `inbound:fetch` cada minuto (necesita el cron del apartado 5 y el **trabajador de colas vivo**: las respuestas y rebotes salen por la cola).
6. ⚠ **La conexión real a IMAP NO está probada contra un servidor de verdad** (los tests usan un buzón falso y correos de ejemplo). Probar primero con `php artisan inbound:fetch --mailbox=support` y un correo de prueba.
7. Tras activarlo: añadir los dominios de cada empresa («Empresas»), revisar las palabras clave («Productos y categorías») y mirar «Correo entrante» a diario las primeras semanas.

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
3. Traer el código (`git pull` o subir el paquete) — **nunca** subir `.env` ni el contenido de `storage/` desde el portátil. `vendor/` tampoco, **salvo si el hosting solo ofrece FTP y no hay Composer** (caso previsto de IONOS, 2026-11-11): entonces se sube un `vendor/` instalado en una carpeta aparte con `composer install --no-dev --optimize-autoloader` (nunca el de desarrollo: lleva PHPUnit y paquetes de pruebas, y su caché de proveedores falla en el servidor). Sin SSH tampoco hay `config:cache`, `queue:restart` ni `migrate`: la configuración se lee del `.env` y el esquema se carga con el SQL de § 2c.
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
- ✅ **Primera cuenta de administrador** en el servidor: resuelta con `scripts/db-export-staff.sh` (§ 2c.5), sin SSH. Correo del administrador **confirmado por la usuaria (2026-11-11): `aplicaciones@lingmarco.es`**.
- ❓ **Validar con la asesoría** los plazos de conservación y **programar `retention:run`**.
- ❓ **Aviso automático** si el trabajador de colas o la copia fallan (hoy solo lo detecta `preflight:check` cuando alguien lo ejecuta).
- Sin probar en el servidor real: nada de lo de este documento se ha ejecutado fuera de la máquina de desarrollo.
