# Defectos conocidos y resultado de la auditoría (Fase 4)

> Auditoría del 2026-11-11: ocho ejes, un revisor de solo lectura por eje (autorización, privacidad, cifrado y
> secretos, esquema de BD, rendimiento, cobertura de tests, código muerto, observabilidad). **Cada hallazgo se
> comprobó después en el código**; lo que no se sostuvo está en «Descartados». Las severidades son las
> reajustadas tras comprobar, no las que dieron los revisores.
> Cuándo tocar este documento: al cerrar un hallazgo (marcarlo con fecha y commit) o al descubrir uno nuevo.

## Resultado por eje

| Eje | Resultado |
|---|---|
| Autorización | Sin hallazgos. Ámbito + policy + doble capa en acciones. (Lectura de código: no se ha probado atacando.) |
| Esquema de BD | Sin hallazgos. FK RESTRICT, CHECK coherentes con los enums, índices presentes. |
| Cifrado y secretos | Sin hallazgos graves. Cabeceras HTTP cerradas (D-1) y notas de producción (D-3). |
| Privacidad | Sin fugas activas; acumulación de datos sin plan de limpieza (D-5, D-6). |
| Observabilidad | Faltas de operación para producción (D-2, D-3, D-4). |
| Rendimiento | Nada urgente; una consulta que crece con los avisos (D-7). |
| Cobertura de tests | Reglas R1–R21 cubiertas; huecos menores (D-9). |
| Código muerto | Una vista sin uso (D-8). |

## Abiertos

### Altos (antes de producción)
- **D-2 · Nadie avisa si la cola o la copia fallan** (2026-11-11: **detectable pero no avisado**). `php artisan preflight:check` ya detecta un trabajador de colas parado (trabajos pendientes más de 10 minutos), trabajos fallidos y copias viejas; supervisor y alternativa por cron están documentados en `docs/plan/produccion.md`. **Falta, y es decisión de la usuaria ❓:** que algo AVISE a una persona automáticamente (hoy solo lo ve quien ejecute el comando) y poner el trabajador y el cron en el servidor real.

### Medios
- **D-7 · `User::visibleNotices()`** filtra con `JSON_EXTRACT` sobre `notifications.data` y se ejecuta en cada
  carga del panel (campana). Sin medir; no urgente con el volumen previsto. Arreglo si crece: columna `ticket_id`
  indexada en `notifications`. Medir antes de tocar.

### Bajos
- **D-9 · Huecos de test menores:** comprobados uno a uno el 2026-11-11; los demás que citó el revisor (copias, reenviar invitación, rol de empresa) YA estaban cubiertos. El de mover a un agente entre equipos con tickets asignados quedó cubierto con D-12.
- **D-10 · Menores de rendimiento:** búsqueda `LIKE '%…%'` en la bandeja (sin índice; medida con 20 000 tickets y 100 000 mensajes: ≤170 ms, ver `p1-busqueda-y-filtros.md`), `select *` en la lista de
  empleados, mensajes y eventos del hilo ordenados en PHP. Irrelevantes al volumen actual.
## Descartados (el revisor se equivocó; comprobado)
- «Stack traces visibles en 403/404/500»: con `APP_DEBUG=false` (valor por defecto de `config/app.php`) no salen.
- «Sin healthcheck»: existe `/up` (`bootstrap/app.php`).
- «N+1 del equipo del asignado en la bandeja»: `team` es una columna con cast de enum, no una relación.
- «Cuatro `visibleTo` por carga de bandeja»: se parte de una sola base y se clona.
- «R9 sin test del traspaso obligatorio»: lo cubren `StaffTest` (traspaso, traspasos inválidos, fallo con
  rollback) y `UserIntegrityTest` (un solo administrador).

## Cerrados
- **D-1 · Cabeceras de seguridad** (2026-11-11): middleware global `SecurityHeaders` (`X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy`, HSTS solo por HTTPS) y `SecurityHeadersTest`. Sin CSP general a propósito (las vistas usan `style="…"`). Verificado con `curl -I` en la app real. Pendiente de commit.
- **D-12 · Cambio de equipo de un agente** (2026-11-11, visto bueno de la usuaria): al cambiar de equipo se liberan, con evento interno `team_changed`, los tickets no cerrados que ya no puede atender; se conservan los que sí. `ChangeAgentTeam` + `UnassignTickets` (parámetro `$onlyDepartments`), `StaffTest::test_team_change_releases_tickets_it_can_no_longer_work_on` (3 mutaciones, todas rojas). Pendiente de commit.
- **D-5 (parte) · Enlaces de contraseña caducados** (2026-11-11): `retention:run` borra los de `password_reset_tokens` más viejos que el plazo más largo de los dos tipos (invitación, 7 días); la simulación los cuenta sin borrar. `RetentionTest::test_expired_reset_tokens_are_purged` (3 mutaciones rojas). Siguen abiertos avisos (`notifications`), `failed_jobs` y `sessions`.
- **D-8 · `welcome.blade.php`** (2026-11-11): borrada, no la usaba nadie (la raíz redirige a `/panel`); queda en el historial de git.
- **D-4 · Auditoría de descargas** (2026-11-11, decisión delegada): solo se audita la descarga DENEGADA (`attachment_denied`: quién y qué adjunto, sin nombre de fichero); las permitidas no, para no llenar la tabla. Los bloqueos R20 ya se auditaban (`login_locked`). `AttachmentsTest::test_denied_download_is_audited`.
- **D-5 · Datos sin plan de conservación** (2026-11-11): avisos de la campana a 2 años, `failed_jobs` a 90 días y enlaces caducados, en `retention:run`; las sesiones las limpia Laravel. 
- **D-6 · Usuarios de baja** (2026-11-11): anonimizados a los 2 años de la baja (columna `users.anonymized_at`; no se reactivan); las empresas quedan FUERA de la purga. Provisional hasta validar con la asesoría.
- **D-13 · Zona horaria de la conexión a MySQL** (2026-11-11, hallazgo mío al cargar datos de prueba): la sesión de MySQL usaba la hora del servidor (aquí Canarias, WET con cambio de hora) mientras Laravel guarda UTC; una fecha dentro de la hora que no existe allí (último domingo de marzo, 01:00-02:00 UTC) **fallaba al guardar** (un ticket o mensaje creado a esa hora daba error 500). Arreglado: `'timezone' => '+00:00'` en la conexión `mysql` (`config/database.php`, variable `DB_TIMEZONE`). `Schema/TimezoneTest` (3; la zona de sesión y el caso de marzo estaban en rojo antes). Pendiente de comprobar en IONOS: el huso de su MySQL (con este arreglo da igual).
- **D-11 · Páginas de error propias** (2026-11-11): `resources/views/errors/` (403, 404, 419, 429, 500, 503) en español, con el logo y el enlace de vuelta, sin sesión ni base de datos y sin el mensaje de la excepción. `ErrorPagesTest` (5; 4 mutaciones rojas). Antes salía la página en inglés de Laravel («404 Not Found»), sin nada que orientara.
- **D-3 · Valores de desarrollo en `.env.example`** (2026-11-11): `.env.production.example` con los valores de producción y la razón de cada uno, y `php artisan preflight:check` que los comprueba (en producción falla con código 1). Guía en `docs/plan/produccion.md`.
