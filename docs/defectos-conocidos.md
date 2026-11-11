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
- **D-2 · Sin supervisión del trabajador de colas.** Si `queue:work` cae, no salen correos ni avisos y nadie se entera
  (CLAUDE.md ya lo avisa). Falta: supervisor (o equivalente en IONOS), y vigilar `failed_jobs`. Es de despliegue (Fase 5).

### Medios
- **D-3 · Valores de desarrollo en `.env.example`** que no valen en producción: `APP_DEBUG=true`, `LOG_STACK=single`
  (sin rotación), `LOG_LEVEL=debug`, `SESSION_ENCRYPT=false`, `MAIL_MAILER=log`. Arreglo: lista de «entorno de
  producción» en la documentación de despliegue (no cambiar el ejemplo, que es el de desarrollo).
- **D-4 · Las descargas de adjuntos no dejan rastro en la auditoría** (`AttachmentController`), ni los accesos
  denegados (403) ni los bloqueos R20. **Decisión de la usuaria:** qué eventos auditar (una descarga por cada
  apertura llenaría la tabla).
- **D-5 · Datos sin plan de conservación:** `notifications`
  (solo se borran las de tickets anonimizados), `failed_jobs`, `sessions`. Rebajado de «crítico»: son acumulación,
  no exposición. Los enlaces caducados de `password_reset_tokens` YA se purgan (ver «Cerrados»). Arreglo del resto en `retention:run` tras decidir plazos: **decisión de la usuaria**.
- **D-6 · Identidad de usuarios de baja en tickets ya anonimizados** (`requester_id`, `assigned_to` se conservan).
  Ya estaba como «sin decidir» (usuarios de baja y empresas, `conservacion-datos.md`).
- **D-7 · `User::visibleNotices()`** filtra con `JSON_EXTRACT` sobre `notifications.data` y se ejecuta en cada
  carga del panel (campana). Sin medir; no urgente con el volumen previsto. Arreglo si crece: columna `ticket_id`
  indexada en `notifications`. Medir antes de tocar.

### Bajos
- **D-9 · Huecos de test menores:** comprobados uno a uno el 2026-11-11; los demás que citó el revisor (copias, reenviar invitación, rol de empresa) YA estaban cubiertos. El de mover a un agente entre equipos con tickets asignados quedó cubierto con D-12.
- **D-10 · Menores de rendimiento:** búsqueda `LIKE '%…%'` en la bandeja (sin índice), `select *` en la lista de
  empleados, mensajes y eventos del hilo ordenados en PHP. Irrelevantes al volumen actual.
- **D-11 · Sin páginas de error propias** (403/404/419/429/500 usan las de Laravel, genéricas con `APP_DEBUG=false`).
  Es de diseño: se hará con los ajustes de diseño.

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
