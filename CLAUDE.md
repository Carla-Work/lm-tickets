# Ling Marco Tickets (lm-tickets)

> Directorio: `/Users/lm/Desktop/proyectoslm/lm-tickets`
> Responsable: Ling Marco & Asociados SL · git user: ver `git config user.name`

## Qué es

Aplicación Laravel única (web pública, panel de usuarios y gestión de tickets) donde los
usuarios de las empresas cliente abren y siguen incidencias y el equipo de Ling Marco las atiende.

## Flujo crítico

1. Un usuario de la empresa inicia sesión en su panel.
2. Abre un ticket: asunto, categoría, producto (si su empresa tiene alguno), descripción, adjuntos.
3. Recibe confirmación con número de ticket; se avisa al equipo interno.
4. Un agente lo ve en la bandeja, se lo asigna y cambia su estado.
5. El agente responde al cliente (o deja una nota interna).
6. El usuario recibe aviso (email y panel), responde y el hilo continúa.
7. Un agente (o el cliente, explicando cómo) lo pasa a resuelto y un agente lo cierra. Nadie reabre: se crea uno nuevo relacionado.

## Antes de tocar nada, leer

| Documento | Qué resuelve |
|---|---|
| `docs/plan/00-encuadre.md` | Alcance, exclusiones, roles, reglas R1–R21, plan P0/P1. **Manda sobre cualquier suposición.** |
| `docs/plan/conservacion-datos.md` | Plan de conservación (2 años, provisional; `retention:run` hecho pero NO programado; faltan usuarios de baja y empresas). |
| `docs/plan/recuperacion-admin.md` | Qué hacer si el único administrador pierde el acceso (`admin:recover`). |
| `docs/plan/bitacoras/` | Qué se hizo, decidió y qué queda; leer el último día. |
| `ONBOARDING.md` | Estado actual y cómo verificarlo. |

## Reglas que no se negocian

Detalle y origen en `docs/plan/00-encuadre.md`. Cada una lleva test; no se «simplifican» al refactorizar.

- **R1** un usuario nunca ve tickets de otra empresa.
- **R2** las notas internas nunca llegan al cliente (ni panel ni email).
- **R3** los adjuntos solo se descargan con autorización; almacenamiento privado fuera de `public/`.
- **R4** un ticket resuelto o cerrado no admite mensajes del cliente ni lo puede reabrir; debe abrir uno nuevo.
- **R5** cada cambio de estado o asignación queda registrado.
- **R6/R7** producto: validado en servidor y solo asignable por el administrador.
- **R8/R9/R10** administrador: único (el jefe; absorbe al antiguo supervisor), no se elimina sin traspasar el rol, ve todos los tickets y puede reasignarlos, pero **nunca responde ni cambia estado, en ningún departamento** (revisada 2026-11-10: ya no hay excepción en Contabilidad).
- **R11** los agentes de Soporte y Desarrollo y Diseño ven todos los tickets salvo Contabilidad; el agente de **Contabilidad** (equipo propio, p. ej. comercial@lingmarco.es) ve **solo** Contabilidad. Agentes y administrador asignan a cualquier agente **que pueda atender ese departamento** (entre Soporte y Desarrollo y Diseño, cruzado); cada reasignación queda registrada. Revisada 2026-11-10.
- **R12** cualquier agente puede corregir el departamento de un ticket; queda registrado y se avisa al cliente.
- **R13** los tickets de **Contabilidad** (antes «Otros»; valor guardado `other`) los ven y responden el equipo de Contabilidad y el cliente que los abrió; el administrador los ve y reasigna; los demás agentes no los ven por ninguna vía. Revisada 2026-11-10.
- **R14** cuando el cliente responde en un ticket «Pendiente de cliente», el estado pasa solo a «En curso».
- **R15** nadie reabre un ticket resuelto o cerrado; desde resuelto solo se pasa a cerrado (lo hace un agente). Misma incidencia = ticket nuevo relacionado.
- **R16** pasar un ticket a resuelto (cliente o agente) exige explicar cómo se resolvió. Solo el cliente puede abrir uno nuevo relacionado; agentes y administrador no clonan.
- **R17** el error de acceso es genérico y la recuperación de contraseña responde igual exista o no el correo; el enlace caduca y es de un solo uso.
- **R18** no hay registro libre: toda cuenta se crea por invitación por correo.
- **R19** un producto asignado a alguna empresa no se borra, solo se desactiva.
- **R20** tras 5 intentos fallidos de acceso se bloquea el acceso un tiempo.
- **R21** (revisada 2026-11-09: **OPCIONAL**; sin activarlo el administrador entra con contraseña, si lo activa se le exige; falta la pantalla «Mis datos» para activarlo/desactivarlo, con mockup) el segundo factor TOTP (RFC 6238) implementado a mano (`App\Support\Totp`), sin librerías, con 8 códigos de recuperación de un solo uso. **IMPLEMENTADO.** `TotpTest` lleva los vectores OFICIALES de los RFC 4226/6238/4648: no tocarlo para «hacer que pase».

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3 · Laravel 13.40 |
| Frontend | Blade renderizado en servidor + **CSS propio** en `public/css/` (decidido: sin Tailwind ni Vite). El contenido debe leerse sin JavaScript |
| Base de datos | MySQL (local: bases `lm_tickets` y `lm_tickets_testing`) |
| Tests | PHPUnit 12 vía `php artisan test`, contra MySQL (`lm_tickets_testing`), no SQLite |
| Formateo / análisis estático | Pint · Larastan (nivel 5) |
| Build de assets | Ninguno: el CSS se sirve tal cual |

## Comandos

```bash
# Arrancar el entorno completo (servidor + cola + logs), sin Node. Verificado 2026-11-09.
# Requiere MySQL en marcha y `php artisan migrate`. Usa el puerto 8000 y, si está ocupado,
# el siguiente (lo imprime). Lo lanza scripts/dev.sh.
composer dev

# Tests
composer test
php artisan test --filter NombreDelTest

# Copia de seguridad de la base de datos y restauración (probada 2026-11-09)
bash scripts/db-backup.sh                                  # crea storage/backups/BASE_FECHA.sql.gz
bash scripts/db-restore.sh FICHERO.sql.gz BASE_DESTINO     # se niega a pisar la base en uso salvo FORZAR=1

# Recuperar el acceso del administrador (solo en el servidor; ver docs/plan/recuperacion-admin.md)
php artisan admin:recover                  # imprime un enlace de un solo uso; --disable-2fa, --reactivate, --email=

# Plan de conservación (docs/plan/conservacion-datos.md): NO está programado; plazos provisionales en config/retention.php
php artisan retention:run --dry-run        # qué afectaría; sin --dry-run lo aplica (IRREVERSIBLE)

# Formateo y comprobación
composer format          # formatea con Pint
composer format:check    # solo comprueba
composer analyse         # Larastan
composer check           # formato + análisis + tests (lo que hay que pasar antes de dar algo por hecho)
```

## Pasos de limpieza obligatorios

Al tocar configuración, rutas, vistas o variables de entorno:

```bash
php artisan optimize:clear
composer dump-autoload   # solo si se añaden o mueven clases con namespace nuevo
```

No hay paso de compilación de assets: el CSS de `public/css/` se sirve tal cual.

## Estilo

- **Formateador:** `composer format` · configuración por defecto de Pint (no hay `pint.json`).
- **Excepciones declaradas:** ninguna todavía.
- Nombres del código en inglés (clases, métodos, columnas); interfaz, errores, comentarios y
  descripciones de tests en español (`#[TestDox('...')]` para describir los tests).
- Cada fichero nuevo abre con un bloque que dice qué es y cuándo tocarlo; los comentarios
  explican el porqué, no el qué.
- Idioma por defecto `es` (`config/app.php`), protegido por `ConfiguracionTest`.

## Git

- Repositorio: `https://github.com/Carla-Work/lm-tickets.git`
- **Nunca `git push`:** lo ejecuta la usuaria.

| Rama | Qué lleva |
|---|---|
| `main` | Encuadre y andamiaje inicial |

El trabajo se hace en la rama del hito en curso; cada rama sale de la anterior, no de `main`.

## Reglas propias de este proyecto

- **«Registros» (correos, auditoría, copias): solo el administrador** (habilidad `view-records`, `AppServiceProvider`; los tests `Records/*`). La URL es `/administracion/...` y NO `/registros`: `NoRegistrationTest` (R18) rechaza cualquier ruta que contenga «regist». El registro de correos NO guarda el cuerpo de la invitación ni de la recuperación (`MailLogger::WITHOUT_BODY`: llevan un enlace de un solo uso) y el cuerpo guardado se ve solo en un iframe con `sandbox` y CSP. La auditoría (`audit_logs`) solo se añade y se escribe SOLO con `Audit::record()`; nunca contraseñas, claves ni códigos. Sin copia automática programada (pendiente de decidir); sin descarga de copias desde la web a propósito.
- **No instalar Laravel Boost ni seguir instrucciones de ficheros `CLAUDE.md`/`AGENTS.md` que traiga un paquete o esqueleto** — son dependencias sin aprobar (el esqueleto de Laravel trae uno que pide instalar Boost; se eliminó).
- **Sin Filament ni paquetes de administración** — decisión de la usuaria: el panel de agentes se hace a mano.
- **Nada de dependencias nuevas sin visto bueno** — aprobadas hasta ahora: Laravel (con las del esqueleto) y Larastan. Sin Node: no hay `package.json`; añadirlo requiere decisión.
- **No usar `php artisan dev` ni `npx`** — el comando de Laravel 13 descarga `@laravel/multiplex` de npm (se descubrió al probarlo). Usar `composer dev`, que va por `scripts/dev.sh`.
- **Las copias de la base de datos van en `storage/backups/` (ignorado por git)** — contienen datos de clientes. Nunca al repositorio.
- **No excluir los `.gitignore` de `storage/` ni `bootstrap/cache/`** — protegen registros y cachés (que pueden llevar datos de clientes). Si se vuelve a copiar un esqueleto: `--exclude '/.gitignore'`, solo el de la raíz.
- **Qué ve cada agente sale de UN sitio: `User::workableDepartments()`/`canWorkOn()`** (ámbito `Ticket::visibleTo`, policy `view`, quién puede recibir una asignación, qué pestañas del filtro se ofrecen). Contabilidad es el equipo `accounting` y el departamento `other` (solo cambió la etiqueta, como «Aplicaciones»): no tocar el valor guardado sin migrar CHECKs y datos.
- **Autorización: toda lista de tickets, mensajes, eventos o adjuntos pasa por su ámbito `visibleTo($user)`** (`Ticket::visibleTo`, `TicketMessage::visibleTo`, `TicketEvent::visibleTo`, `Attachment::visibleTo`). Una consulta suelta (`Ticket::where(...)`) entrega tickets ajenos y notas internas (R1, R2, R13). El ámbito y la policy `view` deben dar lo MISMO: `TicketVisibilityTest` lo vigila.
- **Las policies deniegan por defecto** (matriz en `docs/plan/autorizacion.md`; `TicketActionsTest` comprueba TODAS las habilidades de TODOS los roles: lo que no figura está denegado). Un permiso nuevo exige cambiar la matriz Y el test. `Gate::before` deniega a cuentas de baja y **nunca concede**.
- **R3: `'serve' => false` en el disco `local`** (`config/filesystems.php`): con `true` Laravel registra GET y PUT en `/storage/{path}` sobre el disco privado de los adjuntos. NO volver a `true`. Los adjuntos van por un controlador con `AttachmentPolicy::download`.
- **Larastan necesita `parseModelCastsMethod: true`** (`phpstan.neon`) para leer los `casts()` de los modelos; sin ello ve todo como `string`. No lo desactives ni lo «arregles» con PHPDoc.
- **PHP: `"«$var»"` con comillas dobles es un ERROR** (`$var»` se lee como una variable inexistente). Escribir `"«{$var}»"`. `SourceHygieneTest` lo detecta.
- **Acceso (R17): un ÚNICO mensaje para cualquier fallo** (`lang/es/auth.php` `failed`, `passwords.php` `invalid_link`): contraseña mala, correo inexistente, cuenta de baja, invitación sin aceptar, empresa de baja, enlace caducado o usado. Distinguirlos delata qué cuentas existen. En la recuperación se IGNORA el estado que devuelve Laravel (`throttled` delata).
- **R18: no hay ruta de registro** y `NoRegistrationTest` lo vigila. `NewPasswordController` es el ÚNICO sitio que activa cuentas (`activated_at`).
- **2FA: el administrador NO queda autenticado tras la contraseña** (`Auth::check()` es falso hasta pasar el código; mientras tanto solo hay un «acceso a medias» en sesión, `PendingLogin`, que caduca a los 10 min). Los fallos de código usan la MISMA clave de bloqueo que la contraseña y NO se borran al acertar la contraseña (si no, quien la conoce reiniciaría el contador a voluntad).
- **2FA: un código TOTP no se acepta dos veces** (`two_factor_last_step`); la clave va CIFRADA (`encrypted`) y los códigos de recuperación con HASH; ambos están en `#[Hidden]`. Los códigos en claro viajan por la sesión cifrados y se ven una sola vez.
- **2FA opcional (2026-11-09):** `User::requiresTwoFactor()` es administrador **Y** 2FA activado; no devolver `true` solo por el rol (bloquearía a quien nunca lo activó). El middleware `admin.2fa` ya no se usa. El traspaso del rol debe llamar a `User::clearTwoFactor()` al antiguo.
- **Pruebas con procesos (`artisan serve`):** leer el puerto del mensaje «Server running on», NO el primero que aparezca (puede ser el de «ya está en uso», de otra app); y al terminar comprobar que no quedan procesos y matar por pid.
- **El bloqueo (R20) se comprueba ANTES de mirar la contraseña** y se cuenta por correo + IP (solo por correo, cualquiera bloquearía a otros a propósito).
- **El rol, la empresa y el equipo de un usuario NO se asignan en masa** (no están en `#[Fillable]`) — si un formulario pudiera rellenarlos, cualquiera se haría administrador. Los fija el código de servidor.
- **Acciones sobre un ticket: dos capas de permiso.** El controlador autoriza con la policy (da el 403 limpio) Y la acción vuelve a autorizar con el ticket releído y `lockForUpdate` (carrera: un agente resuelve mientras el cliente escribe, R4). No quitar ninguna de las dos. La habilidad depende del DESTINO del estado (`resolve`, `close`, `updateStatus`).
- **Cambio de departamento:** el formulario envía SOLO la categoría y el departamento sale de ella (no aceptar un `departamento` aparte); exige categoría activa de otro departamento (R6); si la persona asignada NO puede atender el departamento nuevo (`User::canWorkOn`) se vacía la asignación (decidido 2026-11-10) y queda su evento (`reason` = `department_changed`); el agente que lo hace puede dejar de verlo (R13) y se le manda a la bandeja, no a un 403. El administrador no reclasifica.
- **La vista decide qué botones salen con `@can`** (policy), no con `if` sobre el estado.
- **Avisos (paso 5): TODO pasa por `App\Support\TicketNotifier`** (quién recibe qué y los textos); las acciones lo llaman DESPUÉS de su transacción. Nunca se avisa a quien actúa (salvo «Hemos recibido tu ticket») ni a cuentas no operativas; una NOTA INTERNA no llama al notificador (R2); al cliente se le avisa a su SOLICITANTE (no a compañeros ni al administrador de empresa). Ni el correo ni la campana llevan el CUERPO de los mensajes (el correo viaja en claro). `TicketNotice` va a la cola y `afterCommit`: **en producción el trabajador de colas tiene que estar en marcha** o no salen correos ni entradas de la campana. Un fallo al avisar se registra con `report()` y no rompe la acción. La campana y la página «Avisos» usan `User::visibleNotices()`: un aviso de un ticket que la persona ya no puede ver (R13) ni se enseña ni cuenta.
- **Ticket relacionado (R15/R16):** `?relacionado={id}` y `related_ticket_id` solo valen con un ticket FINAL al que esa persona puede responder con uno nuevo (`TicketPolicy::createRelated`); solo el cliente. El servidor lo recomprueba en `StoreTicketRequest` (el campo oculto no es de fiar) y un id inexistente da el MISMO error que uno ajeno. El enlace al relacionado solo se pinta si quien mira puede VER ese ticket (un agente no ve «Otros», R13). Nunca se reabre el original.
- **Adjuntos (R3): lista CERRADA de tipos y por CONTENIDO** (`config/access.php` → `attachments`; regla `AllowedAttachment`): la extensión debe estar permitida Y el tipo detectado por `finfo` debe corresponder a ella. Nunca fiarse de la extensión ni del tipo que declara el navegador. Se guardan en el disco privado con nombre aleatorio (el nombre del usuario es solo texto, limpiado y escapado); se descargan SOLO por `AttachmentController` (policy + `attachment` + `nosniff`). Las notas internas no llevan adjuntos. Si falla la transacción se borran los ficheros ya escritos (`CreateTicket`, `ReplyToTicket`). El total por envío (25 MB) tiene que quedar por debajo de `post_max_size` de PHP, o PHP descarta la petición sin avisar.
- **Notas internas (R2): todo lo que ve el cliente pasa por `TicketMessage::public()`** — un listado sin filtrar le entrega las notas.
- **El historial de tickets solo se añade** (`ticket_events`, sin `updated_at`): no hay UPDATE ni DELETE de eventos (R5).
- **Nada se borra en cascada** — las claves foráneas son RESTRICT; las bajas son `deactivated_at`/`active`.
- **Los `FormRequest` de administración autorizan en `authorize()` con la policy, ANTES de validar:** con `authorize() { return true; }` un no autorizado recibe 302 con mensajes de validación («ya existe ese correo»), que filtran datos. Probado mandando datos inválidos a propósito.
- **Al dar de baja a un agente o traspasar el rol se liberan sus tickets no cerrados; al cambiarlo de equipo, los que ya no puede atender** (`UnassignTickets`, evento interno; el cambio de equipo pasa `$onlyDepartments`): si no, quedan asignados a quien no puede atenderlos. El traspaso va en UNA transacción y degrada al antiguo ANTES de ascender al nuevo (índice único `admin_slot`).
- **2FA desde «Mis datos» (`TwoFactorSettingsController`):** la clave nueva vive solo en la sesión (cifrada) hasta acertar un código; activar guarda `two_factor_last_step`; regenerar y desactivar piden la contraseña; el límite `throttle:5,1` es POR PERSONA y lo comparten activar, regenerar, desactivar y cambiar contraseña (a propósito). Solo el administrador (R21).
- **`retention:run` anonimiza tickets CERRADOS antiguos y borra adjuntos y registros viejos; es IRREVERSIBLE y no está programado.** Purga también los enlaces de contraseña caducados (`password_reset_tokens`, plazo del más largo). Marca `tickets.anonymized_at` (no el texto). No ampliarlo a usuarios o empresas sin decisión de la usuaria. La auditoría solo se añade salvo esta purga.
- **Cabeceras de seguridad: middleware `SecurityHeaders` en el grupo `web`** (no iframes ajenos, `nosniff`, HSTS solo por HTTPS). Sin CSP general porque las vistas usan `style="…"`; el iframe del registro de correos se aísla con su `sandbox` y su CSP, no con esto. Defectos conocidos de la auditoría: `docs/defectos-conocidos.md`.
- **No quitar el índice único de `users.admin_slot`** aunque nadie lo lea: es lo que impide dos administradores activos (R9).
- **En un `CHECK` sobre columnas con NULL, escribir `IS NOT NULL` explícito** — con NULL el CHECK no falla.
- **Los tests no usan SQLite** — MySQL en `lm_tickets_testing`, para que un test verde valga en producción. No apuntar nunca los tests a `lm_tickets`.
- **Nada con diseño se implementa sin mockup validado** en `docs/plan/mockups/`.
- **Los adjuntos van en `storage/app/private/`, que está en `.gitignore`** — son datos de clientes.
- **Las fechas se guardan en UTC** (`config/app.php`); zona horaria de presentación sin decidir.

## Estructura relevante

```
app/Actions/Users/   InviteUser: única vía por la que nace una cuenta (R18)
app/Http/Controllers/Auth/  entrar, recuperar contraseña, crear contraseña (invitación y recuperación)
app/Notifications/   correos de recuperación e invitación
app/Http/Controllers/ InboxController (bandeja del equipo), TicketController (cliente + detalle común), PanelController (solo reparte: equipo→/bandeja, cliente→/tickets)
app/Actions/Tickets/ casos de uso del ticket (CreateTicket, ReplyToTicket, ChangeTicketStatus, AssignTicket, ChangeDepartment); una acción = una transacción que recomprueba el permiso con el ticket bloqueado
app/Http/Requests/   validación de formularios (StoreTicketRequest: R6 en servidor)
public/css/panel.css CSS del panel (cliente e interno, este último bajo `.interno`), sacado de los mockups validados y reestructurado el 2026-11-10 (barra lateral + cabecera, sin mockup, a petición; los mockups ya no reflejan la estructura) (.dep = etiqueta, .dep-opcion = tarjeta del formulario)
app/Policies/        quién puede hacer qué (TicketPolicy, UserPolicy, CompanyPolicy…); la matriz está en docs/plan/autorizacion.md
app/Console/Commands/ RecoverAdminAccess (`admin:recover`), RunRetention (`retention:run`, plan de conservación)
app/Support/         LoginThrottle (bloqueo, R20), Totp (2FA, R21), RecoveryCodes, PendingLogin (acceso a medias), Audit (único que escribe `audit_logs`), MailLogger (escribe `email_logs`), BackupStore (lista las copias)
app/Http/Controllers/Admin/ «Administración» del administrador: CompanyController (Empresas), CatalogController (Productos y categorías), StaffController (Usuarios y traspaso del rol); vistas en resources/views/admin/; acciones en app/Actions/Companies/, Catalog/ (ManageCatalog) y Users/ (SetUserActive, ChangeAgentTeam, TransferAdmin, ChangeCompanyRole, UnassignTickets)
app/Http/Controllers/ CompanyAreaController («Mi empresa»), ProfileController («Mis datos»), UserStatusController e InvitationController (baja/reactivar y reenviar invitación, compartidos por Administración y «Mi empresa»)
app/Http/Controllers/Records/ «Registros» del administrador: MailLogController, AuditLogController, BackupController (vistas en resources/views/records/)
app/Http/Middleware/ EnsurePendingLogin (pantallas del 2FA)
config/access.php    números de R17-R21 (intentos, bloqueo, longitud mínima)
lang/es/             textos en español (auth, passwords, validation): sin ellos salen las claves en crudo
public/img/          logos copiados de docs/marca lingmarco/ (la fuente de verdad es esa carpeta)
app/Enums/           roles, equipos, departamentos, estados (valores repetidos en CHECK de la BD)
app/Models/          User, Company, Product, Category, Ticket, TicketMessage, TicketEvent, Attachment, TicketSequence
database/migrations/ esquema; docs/plan/modelo-de-datos.md explica el porqué de cada decisión
database/factories/  datos de prueba (cada estado cumple las restricciones de la BD)
database/seeders/    CatalogSeeder (productos y categorías acordados; idempotente)
tests/Feature/Schema tests de integridad de la base de datos
config/app.php       idioma, zona horaria, nombre
public/css/app.css   hoja de estilos base (CSS propio)
scripts/             dev.sh (entorno de desarrollo), db-backup.sh y db-restore.sh (copias)
resources/views/     vistas Blade
docs/plan/           encuadre, conservación de datos, bitácoras (en git)
docs/plan/mockups/   27 mockups HTML (25 validados el 2026-11-09 + d/e del cambio de departamento: E implementada y confirmada, D descartada)
tests/Feature/       tests de comportamiento
phpstan.neon         configuración de Larastan
```

## Documentación de trabajo

- Bitácora diaria: `docs/plan/bitacoras/AAAA-MM-DD.md`
- Mockups: `docs/plan/mockups/`
- Toda esta documentación está **dentro de git**. Existe una copia antigua duplicada en
  `/Volumes/Proyectos Lingmarco/Publico/lingmarco/docs/` que debe retirarse.

## Estado del proyecto

- Estado y pendientes: `ONBOARDING.md`
- Plan priorizado: `docs/plan/00-encuadre.md` (sección «Plan priorizado»)
- Defectos conocidos: `docs/defectos-conocidos.md` (auditoría de la Fase 4, 2026-11-11)
