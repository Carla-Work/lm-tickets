# Modelo de datos

Estado: **esquema aprobado por la usuaria el 2026-11-09** (paso 1 de la Fase 2). Este documento
explica el POR QUÉ de cada decisión; el cómo está en `database/migrations/` y `app/Models/`.
Si cambias el esquema, actualiza esto.

## Principios

1. **Nada se borra en cascada.** Las bajas son estados (`deactivated_at`), no `DELETE`: los
   tickets y su historial son el registro de lo que se hizo y se conservan según el plan de
   conservación (`conservacion-datos.md`). Todas las claves foráneas son `RESTRICT`.
2. **Lo que se puede garantizar en la base de datos, se garantiza en la base de datos.** Un fallo de
   la aplicación no debe poder dejar datos imposibles. Lo que no cabe en una restricción
   (p. ej. «el producto es de la empresa del solicitante», R6) se valida en la aplicación **y** se
   prueba.
3. **Los valores de las enumeraciones están repetidos en la base** (`CHECK`). Un test
   (`EnumsMatchDatabaseTest`) falla si se añade un caso a una enumeración y se olvida la restricción.

## Tablas

| Tabla | Qué guarda | Decisiones |
|---|---|---|
| `companies` | Empresas cliente | `deactivated_at` en lugar de borrar. `tax_id` (CIF) único. |
| `users` | Clientes y empleados (la tabla del esqueleto, ampliada) | Ver «Usuarios». |
| `products` | Catálogo de productos y servicios | `active` en lugar de borrar (R19). |
| `company_product` | Productos contratados por cada empresa | Clave primaria compuesta; `RESTRICT` en ambos lados (R19). |
| `categories` | Categorías, colgadas de un departamento | Único por (departamento, nombre). Editables por el administrador. |
| `tickets` | El ticket | Ver «Tickets». |
| `tags` | Etiquetas internas (catálogo cerrado del administrador) | Nombre único; `active`: se desactivan, no se borran. Ver «Prioridad y etiquetas internas». |
| `ticket_tag` | Etiquetas de cada ticket | Clave primaria compuesta (ticket, etiqueta); `RESTRICT` en ambos lados. |
| `ticket_sequences` | Contador del número de ticket por año | Ver «Número de ticket». |
| `ticket_messages` | Hilo: mensajes públicos y notas internas | Ver «Notas internas (R2)». |
| `ticket_ratings` | Valoración de la atención (1-5 y comentario opcional) | Una por ticket (`ticket_id` único), CHECK 1-5, `RESTRICT`. El comentario es dato personal: la purga lo sustituye. Ver «Valoración de la atención». |
| `ticket_events` | Historial de cambios (R5) | Solo se añade: no tiene `updated_at`. |
| `attachments` | Adjuntos de un mensaje | Solo guarda la ruta en almacenamiento **privado** (R3). |
| `notifications` | Avisos en el panel | Tabla estándar de Laravel. |

Los 4 **departamentos** (Soporte, Aplicaciones, Páginas web, Contabilidad —valor guardado `other`, antes «Otros»—) y los 3 **equipos**
(Soporte, Desarrollo y Diseño, Contabilidad) son fijos y viven en `app/Enums/`, no en tablas: llevan los colores de marca
y definen quién ve qué (R13); cambiarlos es cambiar código, a propósito.

## Usuarios

Una sola tabla para clientes y empleados, con `role` (`company_user`, `company_admin`, `agent`,
`admin`). Restricciones en la base de datos:

- Un cliente **tiene** empresa; un empleado **no** (`users_role_company_check`).
- Un agente **tiene** equipo; el resto **no** (`users_team_check`).
- **Solo puede haber un administrador activo** (R9): columna generada `admin_slot` (vale 1 si
  `role = 'admin'` y no está desactivado, `NULL` si no) con índice único. Los `NULL` no chocan, así
  que puede haber administradores *desactivados* de sobra, pero nunca dos activos. El traspaso del
  rol se hace en una transacción: primero se degrada al antiguo, luego se asciende al nuevo.
- `activated_at` nulo = cuenta **invitada**, aún sin contraseña elegida (R18). `deactivated_at` =
  baja. El rol **no** se puede asignar por `fill()` (no está en `#[Fillable]`): lo fija el código
  de servidor, nunca el formulario.

## Tickets

- `department` es la etiqueta que elige el cliente; el **equipo** que lo atiende se deduce de ella
  (`Department::team()`), no se guarda: así no hay dos verdades que puedan discrepar.
- `product_id` es opcional en la base de datos (hay empresas sin productos) pero la aplicación lo
  exige cuando la empresa tiene alguno (R6).
- `status`: `open`, `in_progress`, `pending_customer`, `resolved`, `closed`.
- `resolution` guarda **cómo se resolvió** (R16), obligatorio al pasar a `resolved`.
- `related_ticket_id` enlaza con el ticket del que se «clonó» (nadie reabre, R15).
- `last_activity_at` permite ordenar la bandeja sin recorrer los mensajes.
- El **primer mensaje** del hilo es la descripción: no hay columna `description` aparte, para no
  tener dos sitios donde mirar.

### Número de ticket

Se ve como `2026-0312`: año y secuencia dentro del año. Se guardan como dos columnas
(`year`, `sequence`, únicas juntas) y el texto se compone al mostrar (`Ticket::number`). La secuencia
la reparte `TicketSequence::next()` bloqueando la fila del año (`SELECT … FOR UPDATE`) dentro de una
transacción: dos tickets creados a la vez no pueden recibir el mismo número. **Trampa:** no usar
`MAX(sequence)+1`; con concurrencia repite números.

### Notas internas (R2)

Mensajes públicos y notas internas viven en **la misma tabla** (`kind`). Se eligió así porque el
hilo se ordena por fecha mezclando ambos y partir en dos tablas obligaría a unirlos en cada vista.
**El riesgo** es obvio: un `SELECT` olvidado filtra una nota al cliente. Por eso:
`TicketMessage::scopePublic()` es la única vía permitida en todo lo que ve el cliente, y el paso 3
(autorización) lleva tests que recorren cada vista, correo y aviso del cliente buscando notas (R2).

### Prioridad y etiquetas internas (P1)

`tickets.priority` (`low`/`normal`/`high`/`urgent`, `NOT NULL DEFAULT 'normal'`, con su CHECK) y las etiquetas (`tags` +
`ticket_tag`) son **internas**: el cliente no las ve en ninguna pantalla, correo o aviso. **El riesgo** es el mismo que con las
notas: una vista olvidada que las pinte. Por eso solo se cargan para el equipo (`TicketController::show`), los cambios
generan eventos con `visible_to_customer = false`, no tocan `last_activity_at` (que ordena la lista del cliente), y
`PriorityAndTagsTest` recorre lista, detalle y avisos del cliente buscando cualquier rastro.

### Quién creó el ticket y por qué canal (P1, 2026-11-12)

`tickets.created_by_id` (NOT NULL, clave foránea RESTRICT a `users`) y `tickets.channel` (`web`/`email`/`phone`/`in_person`/`other`,
NOT NULL DEFAULT `web`, con su CHECK; valores en `App\Enums\TicketChannel`). Los dos son **internos**: el cliente no los ve en
ninguna pantalla, correo o aviso. En un ticket que abre el cliente, `created_by_id = requester_id` y `channel = web`; solo difieren
cuando una persona del equipo lo crea a nombre de un cliente (`CreateTicket` con `StaffCreation`). En ese caso el primer mensaje y el
evento visible `created` van a nombre del CLIENTE (así ve el ticket como suyo) y el historial interno lleva un evento
`created_by_staff` (`visible_to_customer = false`) con el canal y la persona asignada. `StaffCreatesTicketTest` busca cualquier rastro
en las pantallas del cliente. La migración rellenó `created_by_id` de los tickets existentes con `requester_id`.

### Correo entrante (P1, 2026-11-12)

Plan: `docs/plan/p1-email-entrante.md`. Tablas nuevas (migración `2026_11_12_200000`):
- `company_domains` (dominio único: de UNA empresa), `product_keywords` (palabra normalizada única: de UN producto) e `incident_words` (normalizada, única).
  Quitar una palabra o un dominio los **borra** (no son datos de clientes). Los dominios gratuitos (gmail…) no se pueden registrar. Solo son PISTAS.
- `inbound_emails`: una fila por correo procesado (`message_id` único: no se procesa dos veces sin tocar el buzón). Estados (CHECK): `pending` (90 días) ·
  `spam` (30) · `ticket` · `reply` · `ignored` · `rejected`. El **cuerpo** solo se guarda en cuarentena (pending/spam); al convertirse en ticket o mensaje se vacía.
- `inbound_email_attachments`: adjuntos de un correo EN CUARENTENA en el disco privado (R3); al crear el ticket se copian al ticket y se borran de aquí.
- `ticket_messages.email_message_id` (único), `via_email` y `emailed_at`: el Message-ID del correo enviado o recibido para ese mensaje, si el del cliente entró
  por correo y si la respuesta del equipo salió por correo. Con el Message-ID una respuesta del cliente entra en SU hilo (cabecera `In-Reply-To`).
- `categories.is_default`: «Sin clasificar» en cada departamento (la categoría de los tickets que llegan por correo). No se ofrece en ningún formulario.
Retención: spam 30 días, el resto 90 (`retention:run`, `docs/plan/conservacion-datos.md`).

### Valoración de la atención (P1)

`ticket_ratings` guarda una valoración por ticket (clave única en `ticket_id`, sin `updated_at`: solo se añade). La puntuación es
estadística y se conserva; el **comentario** es texto libre del cliente y `retention:run` lo sustituye al anonimizar el ticket.
Se pinta siempre escapado. Valorar no es un mensaje: no mueve `last_activity_at` ni avisa a nadie.

## Cómo se comprueba

`tests/Feature/Schema/` (39 tests) prueba **la base de datos**, no la aplicación: cada restricción
tiene su test, y cada uno se ha visto ponerse rojo al romper la garantía (índice único del
administrador, claves foráneas `RESTRICT`, ámbito de notas internas). Comandos: `composer test`.

**Lección aprendida (SQL):** un `CHECK` solo rechaza cuando da *falso*, no cuando da *NULL*. La primera
versión de `users_team_check` aceptaba un agente sin equipo porque `team IN (...)` con `team = NULL`
da NULL. Al escribir un `CHECK` sobre columnas que admiten NULL, hay que decir `IS NOT NULL` explícito.

## Lo que NO está en el esquema (a propósito)

- Preferencias de notificación: no son configurables (decidido).
- Plazos de conservación: pendientes de decidir; cuando se decidan se añadirá lo que haga falta
  (p. ej. anonimización) con su migración.
- (Hecho) Segundo factor del administrador (R21): columnas `two_factor_*` en `users` (migración `add_two_factor_columns_to_users_table`): clave CIFRADA, fecha de confirmación, códigos de recuperación con HASH y último intervalo usado (anti-reutilización).

## Pendiente de comprobar en IONOS

Las restricciones `CHECK` y las columnas generadas necesitan MySQL ≥ 8.0.16 o MariaDB ≥ 10.2.
**No se ha comprobado qué base de datos ofrece el plan de IONOS.** Hay que verificarlo antes de
desplegar (el motor de desarrollo es MySQL 9.6).
