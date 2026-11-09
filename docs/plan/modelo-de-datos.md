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
| `ticket_sequences` | Contador del número de ticket por año | Ver «Número de ticket». |
| `ticket_messages` | Hilo: mensajes públicos y notas internas | Ver «Notas internas (R2)». |
| `ticket_events` | Historial de cambios (R5) | Solo se añade: no tiene `updated_at`. |
| `attachments` | Adjuntos de un mensaje | Solo guarda la ruta en almacenamiento **privado** (R3). |
| `notifications` | Avisos en el panel | Tabla estándar de Laravel. |

Los 4 **departamentos** (Soporte, Aplicaciones web, Páginas web, Otros) y los 2 **equipos**
(Soporte, Aplicaciones) son fijos y viven en `app/Enums/`, no en tablas: llevan los colores de marca
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
- Segundo factor del administrador (R21): método decidido (TOTP a mano), pero **las columnas no están**: se añadirán con su migración (secreto cifrado, fecha de confirmación y códigos de recuperación con hash) cuando se validen los mockups y se implemente.

## Pendiente de comprobar en IONOS

Las restricciones `CHECK` y las columnas generadas necesitan MySQL ≥ 8.0.16 o MariaDB ≥ 10.2.
**No se ha comprobado qué base de datos ofrece el plan de IONOS.** Hay que verificarlo antes de
desplegar (el motor de desarrollo es MySQL 9.6).
