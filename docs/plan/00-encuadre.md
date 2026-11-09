# Encuadre — Gestión de tickets (Ling Marco & Asociados SL)

Estado: **APROBADO por la usuaria el 2026-11-09**, con las decisiones abiertas marcadas con ❓.
Todo lo que tenga diseño necesita además su mockup validado antes de implementarse.

## Marca y departamentos (indicado por la usuaria 2026-11-09)

Guía de colores: la de https://lingmarco.es/.

| Uso | Color |
|---|---|
| Texto | `#040f24` |
| Títulos | `#3c1d6f` |
| Principal | `#742bba` → `#9e4af0` |
| Secundario | `#486330` → `#8cb73c` |
| Gris | `#ededed` |

Departamentos, con los colores del logo (`docs/marca lingmarco/logo-LM.svg`):

| Departamento | Color | Tono de marca (relleno) | Tono para texto |
|---|---|---|---|
| Aplicaciones web | Verde | `#7eba28` | `#486330` |
| Páginas web | Rosa | `#e6318a` | `#a00860` |
| Soporte | Azul | `#3c76ba` | `#1f4f8c` |
| Otros (antes «Comercial y contable») | Amarillo | `#ffe90b` | `#6b5a00` |

El tono de marca sobre fondo blanco no cumple contraste como texto, por eso cada departamento
lleva un segundo tono más oscuro para rótulos. El color nunca es el único indicador: va siempre
con el nombre del departamento.

**Validado por la usuaria (2026-11-09) con el mockup de «Nuevo ticket»:** los departamentos son un campo del ticket que el usuario elige
y que determina a quién llega. Entonces las categorías pasarían a colgar de un departamento
(la propuesta de la sección «Categorías» se reorganiza, y aparece «Otros», que
no estaba; recoge presupuestos, facturas y contratos). Mockup:
`docs/plan/mockups/nuevo-ticket-a-departamentos-tarjetas.html`. ❓ Sigue por decidir: si los
agentes pertenecen a un departamento y solo ven los suyos (afectaría a R1–R3 y a la bandeja).

## Productos por empresa (decidido 2026-11-09)

- Dos campos independientes en el ticket: **categoría** (tipo de problema) y **producto**
  (aplicación o servicio concreto).
- Existe un **catálogo de productos** editable desde la aplicación, no fijo en el código.
- A cada empresa se le asignan uno o varios productos. **Siempre lo hace el administrador.**
- El producto es **obligatorio solo si la empresa tiene alguno asignado**; si no tiene, el campo no se pide.
- El usuario solo ve en el desplegable los productos de su empresa.
- El administrador de empresa ve los productos asignados a su empresa en **solo lectura**.
- Catálogo inicial: oneCOP, oneFact, oneCAR, oneSIGW, oneHADA, más servicios de soporte
  (propuesta tomada de lingmarco.es ❓ a validar: Pool de mantenimiento, Asistencia
  informática, Backup de datos, Mantenimiento web, Servicios de seguridad).
- El catálogo lo gestiona el administrador de la aplicación (el rol de supervisor se fusionó con él, ver «Roles»).

## Decisiones cerradas en la segunda ronda (2026-11-09)

- Visibilidad: el administrador de empresa ve todos los tickets de su empresa; el usuario solo los suyos.
- Estados: Abierto · En curso · Pendiente de cliente · Resuelto · Cerrado.
- Antivirus de adjuntos: fuera del MVP, riesgo aceptado.
- Conservación de datos: **2 años provisionales**; el plan completo se hace más adelante
  (borrador en `conservacion-datos.md`).

## Alcance (una frase)

Módulo de tickets de soporte, integrado en la web de Ling Marco & Asociados SL, donde los
usuarios de las empresas cliente abren y siguen incidencias desde su panel y el equipo interno
las atiende.

## Decisiones tomadas

| Tema | Decisión | Fecha |
|---|---|---|
| Stack | PHP + Laravel, aplicación única (web + panel de usuarios + tickets) | 2026-11-09 |
| WordPress | Descartado | 2026-11-09 |
| Panel de agentes | A mano, **sin Filament** ni otro paquete de administración | 2026-11-09 |
| Cuentas | La empresa es la cuenta; una empresa tiene varios usuarios | 2026-11-09 |
| Notificaciones | Email y dentro del panel | 2026-11-09 |
| Idioma | Español (interfaz, errores, comentarios, descripciones de tests) | 2026-11-09 |
| Despliegue | IONOS, en `https://publico.lingmarco.com/lingmarco/` mientras dure el diseño. La infraestructura definitiva se decide tras aprobar diseño y funcionalidad | 2026-11-09 |

## Categorías de incidencia (propuesta ❓ a validar)

Basadas en los servicios de https://lingmarco.es/ (soporte y mantenimiento, desarrollo a
medida, apps ONE, webs) y en lo indicado por la usuaria (correo). Deben ser editables por el
administrador, no fijas en el código.

1. Página web (incidencia o cambio en una web)
2. Aplicación de gestión (apps ONE y desarrollos a medida)
3. Equipos y sistemas (ordenadores, sistemas operativos, red, impresoras)
4. Correo electrónico
5. Copias de seguridad
6. Seguridad (antivirus, firewall, accesos)
7. Otra consulta

El subtipo de aplicación (oneCOP, oneFact…) ya no va en la descripción: es el campo
**producto**, ver "Productos por empresa".

## Exclusiones del MVP (lo que NO hará)

- Chat en tiempo real.
- SLAs, escalado y automatizaciones.
- Facturación o imputación de tiempo.
- App móvil.
- Base de conocimiento o chatbot.
- Creación de tickets por email entrante (candidato a P1).
- Escaneo antivirus de adjuntos (riesgo aceptado en MVP, confirmado).
- Informes y estadísticas (candidato a P1).

## Roles (propuesta ❓ a validar)

Clientes (pertenecen a una empresa):
- **Administrador de empresa:** ve todos los tickets de su empresa, gestiona los usuarios de la empresa.
- **Usuario:** abre tickets y ve solo los suyos (decidido).

Equipo interno:
Equipos internos (decidido 2026-11-09): hay **dos equipos**, **Soporte** (dos empleados) y
**Aplicaciones**, que lleva Aplicaciones web y Páginas web. No coinciden uno a uno con los cuatro
«departamentos» que elige el cliente (Soporte, Aplicaciones web, Páginas web, Otros): esos son
una etiqueta del ticket, y el equipo es dónde trabaja cada empleado.

| Departamento que elige el cliente | Equipo interno que lo atiende |
|---|---|
| Soporte | Soporte |
| Aplicaciones web | Aplicaciones |
| Páginas web | Aplicaciones |
| Otros | **Ninguno: solo el administrador** (el jefe de Ling Marco) lo ve y lo responde |

El cliente puede equivocarse de departamento. Por eso (decidido 2026-11-09):
- **Cualquier agente puede reasignar** un ticket a alguien del otro equipo (Soporte ↔ Aplicaciones).
- **Cualquier agente puede corregir el departamento** del ticket. Queda registrado (R5) y **se
  avisa al cliente** por email y en el panel (R12).

**Otros es un caso aparte:** lo ve y lo responde **solo el administrador**. Los agentes no lo ven
(R13).

- **Agente** (empleado de Soporte o de Aplicaciones): atiende tickets, escribe respuestas y notas
  internas. **Ve todos los tickets de Soporte, Aplicaciones web y Páginas web**, no solo los de
  su equipo (decidido): sirve para detectar incidencias parecidas y cubrir a un compañero
  ausente. **No ve los de «Otros»** (R13). Puede asignar un ticket a cualquier otro agente,
  también del otro equipo, o asignárselo a sí mismo (R11), y corregir el departamento (R12).
  **No puede responder en un ticket asignado a otro sin asignárselo antes** (decidido): se lo
  asigna, queda registrado y desde entonces lo lleva.
- **Administrador de la aplicación** (decidido 2026-11-09): es el jefe de Ling Marco. **Un solo
  administrador.** El antiguo rol de «supervisor» se **fusiona** con éste: ya no existe como rol
  aparte (ver «Cambio de roles» abajo). Puede:
  - Crear y gestionar las cuentas de los empleados (agentes) y dar de alta las empresas cliente.
  - Gestionar el catálogo de productos, asignarlos a las empresas y gestionar las categorías.
  - **Ver todos los tickets, con sus notas internas**, y **reasignarlos** a cualquier agente
    (decidido 2026-11-09). No responde ni cambia el estado (R10)… **salvo en los de «Otros»,
    que solo él ve y que sí responde y gestiona, incluido pasarlos a otro departamento** (R13).
  - No puede eliminarse, desactivarse ni quitarse el rol mientras sea el único: antes debe
    nombrar a otro empleado de Ling Marco como administrador (traspaso). Ver R9.
- Los empleados de Ling Marco no se registran solos: solo el administrador de la aplicación
  puede crear sus usuarios. Los agentes no los crean ni los gestionan.
- ⚠ Riesgo de tener un único administrador: si pierde el acceso (contraseña olvidada, 2FA
  perdido), nadie puede gestionar cuentas. Hace falta un procedimiento de recuperación fuera
  de la aplicación: un comando que se ejecuta en el servidor y restablece el acceso del
  administrador, documentado en el repositorio (aprobado). Tarea de P0.

### Cambio de roles (2026-11-09): el supervisor desaparece (confirmado)

La usuaria confirmó que «el supervisor es el administrador de la empresa»: el rol **supervisor se
fusiona con el administrador** y queda un único jefe con las funciones de ambos. Consecuencias,
ya aceptadas o resueltas:
- **Reasignar tickets:** pueden los agentes y también el administrador (decidido). El
  administrador no responde ni cambia estados, salvo en «Otros».
- Solo hay **una persona** con poder de gestión; si falta, nadie asigna productos ni crea cuentas
  (cubierto por la recuperación del acceso y el traspaso, R9).
- **Ausencia del administrador (decidido):** no hay suplente dentro de la aplicación. Cuando
  vaya a estar ausente **debe avisar él a los clientes**, que llamarán o escribirán por correo.
  Los tickets de «Otros» esperan hasta su vuelta. ❓ Opcional, a decidir: un aviso en el panel del
  cliente («Otros» atendido solo por el administrador, ausente hasta tal fecha, contactar por
  teléfono o correo). No se hace sin que la usuaria lo pida.
- **Reclasificar (decidido):** el administrador puede pasar un ticket de «Otros» a otro
  departamento; queda registrado y se avisa al cliente (R12), y desde ese momento lo ven los
  agentes.

## Estados del ticket (decidido 2026-11-09)

Abierto · En curso · Pendiente de cliente · Resuelto · Cerrado.

- **Pendiente de cliente → En curso:** automático al responder el cliente (R14).
- **Resuelto:** lo pone un agente, o el cliente (pasa **directamente** a «Resuelto», pero **debe explicar cómo se ha resuelto**). Un ticket puede estar «Resuelto» sin estar «Cerrado».
- **Cerrado:** lo pone **un agente**; no hay cierre automático por tiempo.
- **Nadie reabre un ticket** (decidido 2026-11-09): ni el cliente, ni un agente, ni el administrador. Desde «Resuelto» **solo se puede pasar a «Cerrado»** (R15). Si es la misma incidencia, se crea un ticket nuevo relacionado con el anterior («clonar»).
- **Clonar (decidido 2026-11-09):** solo lo hace el **cliente**, con «Abrir un ticket nuevo» (formulario con el relacionado anotado y departamento, tipo y producto rellenos). **Ni agentes ni administrador clonan** desde la vista interna.
- **Explicar cómo se resolvió (R16):** lo exige el cliente al marcar «resuelto» **y también el agente** al pasar un ticket a «Resuelto». Es el registro de la solución.
- **En «Resuelto» (aún sin cerrar):** el agente puede **escribir mensajes al cliente y notas internas** y cerrar el ticket. El cliente ve los mensajes pero no puede responder. **Reasignar y cambiar el departamento están bloqueados** (decidido 2026-11-09).
- **«Cerrado» es el estado final** (decidido): nadie escribe nada más, ni mensajes ni notas.
- Un agente que no lleva el ticket no cambia su estado sin asignárselo antes; sí puede dejar notas internas (R11).

## Acceso, administración y panel del cliente (decididos y propuestos 2026-11-09)

Mockups hechos para todas las pantallas del flujo y de la administración (lista en `ONBOARDING.md`).
Decisiones que salen de ellos, **todas pendientes de validar** salvo lo marcado:

- **Entrada única** para clientes y empleados; la aplicación lleva a cada uno a su panel.
- **Estilo del acceso** (indicado por la usuaria con una captura de otra herramienta de Ling Marco, onePULSE): pantalla partida, panel oscuro de marca a la izquierda con titular y descripción, formulario sobrio a la derecha, ojo para ver la contraseña. Con los colores de Ling Marco; el logo va sobre una pastilla blanca. No se incluye el botón de tema claro/oscuro de la referencia.
- **Sin registro libre** (R18). El administrador de Ling Marco da de alta empresas y su primer usuario; el
  administrador de empresa gestiona los usuarios de su empresa; el administrador de Ling Marco crea
  a los empleados. Todos por invitación.
- **Catálogo** de productos y categorías: lo edita el administrador. Los 4 departamentos son fijos.
- **«Mi empresa»** (administrador de empresa): ve sus productos en solo lectura y gestiona sus usuarios.
  Para pedir un producto nuevo se abre un ticket en «Otros» (propuesta).
- **Administrador de empresa**: ve los tickets de toda su empresa, con columna «Abierto por».
- **Avisos** (campana): lista con lo que también llega por correo; no configurables (decidido).
- **«Mis datos»:** el correo no se cambia desde ahí; no se cita el plazo de conservación al cliente mientras sea provisional.

❓ Sin decidir (declarado en los propios HTML):
1. Segundo factor (2FA): no hay. Con un único administrador con poder total, merece decisión explícita. «Recordarme en este equipo»: está en el acceso por la pantalla de referencia, pero es una decisión de seguridad sin tomar.
2. Bloqueo tras varios intentos fallidos de acceso.
3. Caducidad del enlace de contraseña (propuesto 60 min) y longitud mínima (propuesto 12).
4. A quién avisa un ticket nuevo: propuesta, al equipo del departamento; los de «Otros», solo al administrador.
5. Si el administrador de empresa puede responder en tickets de sus compañeros (propuesta: solo leer).
6. Si una empresa puede tener varios administradores de empresa (se permite en el mockup).
7. Qué ve un usuario normal en «Mi empresa» (propuesta: solo productos).
8. Confirmación extra al dar de baja una empresa.

## Flujo crítico (7 pasos)

1. Un usuario de la empresa inicia sesión en su panel.
2. Abre un ticket: asunto, categoría (tipo de incidencia), producto (si su empresa tiene alguno asignado), descripción, adjuntos opcionales.
3. Recibe confirmación con número de ticket; se avisa al equipo interno.
4. Un agente lo ve en la bandeja, se lo asigna y cambia su estado.
5. El agente responde al cliente (o deja una nota interna).
6. El usuario recibe aviso (email y panel), responde, y el hilo continúa.
7. Un agente lo pasa a «Resuelto», o el cliente lo marca como resuelto explicando cómo; después un agente lo cierra. **Nadie reabre un ticket**: si es la misma incidencia, se crea uno nuevo relacionado (se «clona»).

## Reglas que no se negocian

| # | Regla | Cómo se protege |
|---|---|---|
| R1 | Un usuario nunca ve tickets de otra empresa | Política de autorización + test |
| R2 | Las notas internas nunca llegan al cliente (ni en panel, ni en email) | Test por cada canal |
| R3 | Los adjuntos solo se descargan con autorización | Almacenamiento privado fuera de `public/`, descarga por controlador + test |
| R4 | Un ticket «Resuelto» o «Cerrado» no admite mensajes del cliente y el cliente no puede reabrirlo; debe abrir uno nuevo | Test de ambos estados y de cualquier vía (formulario, URL directa) |
| R5 | Cada cambio de estado o asignación queda registrado (quién y cuándo) | Historial + test |
| R6 | Un ticket solo puede llevar un producto asignado a la empresa del solicitante; si la empresa tiene productos, es obligatorio | Validación en servidor (no solo en el desplegable) + test |
| R7 | Solo el administrador asigna productos a empresas | Política de autorización + test |
| R8 | Solo el administrador de la aplicación crea, desactiva o cambia el rol de empleados internos; nadie puede darse a sí mismo un rol superior | Política de autorización + test |
| R9 | Siempre existe exactamente un administrador activo: no puede eliminarse, desactivarse ni degradarse sin traspasar antes el rol a otro empleado interno | Validación en servidor + test (incluido el intento de saltarlo) |
| R10 | El administrador ve todos los tickets y puede reasignarlos (R11), pero no responder ni cambiar su estado, salvo en los de «Otros» (R13) | Política de autorización + test de que todas las acciones de escritura devuelven denegado |
| R11 | Un agente o el administrador puede asignar un ticket a cualquier agente, de su equipo o del otro (Soporte ↔ Aplicaciones), o asignárselo a sí mismo; cada reasignación queda registrada con quién, cuándo y de quién a quién, y avisa a la persona que lo recibe | Test + historial (R5) |
| R12 | Cualquier agente puede corregir el departamento de un ticket; queda registrado (quién, cuándo, de cuál a cuál) y se avisa al cliente por email y en el panel | Test + historial (R5) + test del aviso |
| R13 | Los tickets del departamento «Otros» solo los ven y gestionan el administrador y el cliente que los abrió; los agentes no los ven (ni en listas, ni en búsquedas, ni en contadores, ni por URL directa). Si un agente corrige un ticket a «Otros», deja de verlo | Política de autorización + test de cada vía de acceso |
| R14 | Cuando el cliente responde en un ticket «Pendiente de cliente», el estado pasa solo a «En curso» | Test |
| R15 | Nadie (cliente, agente ni administrador) puede reabrir un ticket «Resuelto» o «Cerrado»; desde «Resuelto» solo se pasa a «Cerrado», y solo lo hace un agente. Para la misma incidencia se crea uno nuevo relacionado | Test de cada transición de estado prohibida y por cada rol |
| R16 | Cuando un ticket pasa a «Resuelto» (lo marca el cliente o lo pasa un agente) debe explicarse cómo se ha resuelto; sin explicación no se acepta | Validación en servidor + test |
| R17 | Acceso: el error de inicio de sesión es genérico («el correo o la contraseña no son correctos») y la recuperación de contraseña responde igual exista o no el correo; el enlace caduca y sirve una sola vez | Test de ambos mensajes y del enlace usado/caducado |
| R18 | No hay registro libre: toda cuenta (empresa, usuario de empresa, empleado) se crea por invitación por correo y quien la recibe elige su contraseña | Test de que no existe ruta de registro público |
| R19 | Un producto asignado a alguna empresa no se borra, solo se desactiva; los tickets antiguos conservan su producto | Test |

Fuente: requisitos de la usuaria y RGPD (R1–R3 por confidencialidad de datos de clientes).

## Datos personales y normativa

Se guarda: nombre y email de usuarios, datos de empresa, contenido de tickets, adjuntos
(pueden contener datos sensibles que no controlamos), historial de actividad.

- Cifrado en tránsito (HTTPS); secretos fuera del repositorio.
- Quién ve qué: ver roles y R1–R3.
- ❓ **Plan de conservación: provisional a 2 años.** Plan completo aplazado por la usuaria;
  hay un borrador con opciones en `docs/plan/conservacion-datos.md`. Hay que cerrarlo,
  con asesoría legal si procede, antes de producción. **No bloquea la Fase 1.**
- Pendiente de definir también: derecho de acceso/supresión (cómo se atiende una petición).

## Supuestos sobre volumen

Volumen desconocido. Se diseña para volumen pequeño (cientos o pocos miles de tickets al
año). Tipos y tamaño máximo de adjuntos configurables. Si crece, cambia el almacenamiento,
no el modelo.

## Plan priorizado

### P0 — bloqueante para producción
1. Andamiaje (Fase 1): repo, `.gitignore`, `CLAUDE.md`, comandos de arranque/test/formato, backup probado.
2. Empresas y usuarios con roles (cliente: administrador de empresa y usuario; interno: agente y administrador de la aplicación); gestión de cuentas internas por el administrador; login. Catálogo de productos y asignación a empresas (pantalla de supervisor, con mockup previo).
3. Crear ticket (con categoría y adjuntos) y verlo en el panel del cliente.
4. Bandeja del agente: ver, asignar, cambiar estado.
5. Hilo de respuestas, notas internas, cierre y reapertura.
6. Notificaciones por email y en el panel.
7. Reglas R1–R19 con tests; comando de recuperación del acceso del administrador, documentado y probado.
8. Test de integración del flujo completo.
9. Plan de conservación de datos definido e implementado.

### P1 — no se toca hasta cerrar P0
- Prioridades y etiquetas avanzadas, búsqueda y filtros avanzados.
- Informes y estadísticas.
- Email entrante.
- Valoración de la atención.
- Antivirus de adjuntos.

## Pendiente antes de la Fase 1

- ❓ Aprobación de este documento.
- ❓ Validar la lista de categorías (propuesta abajo).
- ❓ Validar los servicios de soporte del catálogo inicial de productos.
- ❓ Cómo encaja el panel en el diseño de la web (las pantallas pasan por mockup antes de implementar).
