# Encuadre — Gestión de tickets (Ling Marco & Asociados SL)

Estado: **BORRADOR PENDIENTE DE APROBACIÓN** (Fase 0, 2026-11-09). Nada de aquí se implementa
hasta que la usuaria lo apruebe. Lo marcado con ❓ es una decisión abierta.

## Productos por empresa (decidido 2026-11-09)

- Dos campos independientes en el ticket: **categoría** (tipo de problema) y **producto**
  (aplicación o servicio concreto).
- Existe un **catálogo de productos** editable desde la aplicación, no fijo en el código.
- A cada empresa se le asignan uno o varios productos. **Siempre lo hace un supervisor.**
- El producto es **obligatorio solo si la empresa tiene alguno asignado**; si no tiene, el campo no se pide.
- El usuario solo ve en el desplegable los productos de su empresa.
- El administrador de empresa ve los productos asignados a su empresa en **solo lectura**.
- Catálogo inicial: oneCOP, oneFact, oneCAR, oneSIGW, oneHADA, más servicios de soporte
  (propuesta tomada de lingmarco.es ❓ a validar: Pool de mantenimiento, Asistencia
  informática, Backup de datos, Mantenimiento web, Servicios de seguridad).
- El catálogo lo gestiona el supervisor. La usuaria habló de "administrador" y aclaró que
  existirá un administrador de la aplicación; su alcance exacto está en "Roles".

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
supervisor, no fijas en el código.

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
- **Agente:** atiende tickets, escribe respuestas y notas internas.
- **Supervisor:** lo del agente más reasignar, ver todo, gestionar categorías, gestionar el
  catálogo de productos y asignarlos a empresas.
- **Administrador de la aplicación** (decidido 2026-11-09): crea y gestiona las cuentas de los
  empleados de Ling Marco (agentes y supervisores) y da de alta a las empresas cliente.
  Es el jefe de la empresa. Decidido:
  - **Ve todos los tickets en solo lectura**, sin poder responder, cambiar estado, asignar ni
    escribir notas. **Ve también las notas internas** (confirmado): es personal interno y
    R2 solo las oculta al cliente.
  - **Da de alta las empresas cliente.**
  - **Hay un único administrador.** No puede eliminarse, desactivarse ni quitarse el rol
    mientras sea el único: antes debe nombrar a otro empleado de Ling Marco como
    administrador (traspaso). Ver R9.
- Los empleados de Ling Marco no se registran solos: solo el administrador de la aplicación
  puede crear sus usuarios.
- ⚠ Riesgo de tener un único administrador: si pierde el acceso (contraseña olvidada, 2FA
  perdido), nadie puede gestionar cuentas. Hace falta un procedimiento de recuperación fuera
  de la aplicación: un comando que se ejecuta en el servidor y restablece el acceso del
  administrador, documentado en el repositorio (aprobado). Tarea de P0.

## Flujo crítico (7 pasos)

1. Un usuario de la empresa inicia sesión en su panel.
2. Abre un ticket: asunto, categoría (tipo de incidencia), producto (si su empresa tiene alguno asignado), descripción, adjuntos opcionales.
3. Recibe confirmación con número de ticket; se avisa al equipo interno.
4. Un agente lo ve en la bandeja, se lo asigna y cambia su estado.
5. El agente responde al cliente (o deja una nota interna).
6. El usuario recibe aviso (email y panel), responde, y el hilo continúa.
7. El ticket se cierra (por el agente o el cliente) y puede reabrirse.

## Reglas que no se negocian

| # | Regla | Cómo se protege |
|---|---|---|
| R1 | Un usuario nunca ve tickets de otra empresa | Política de autorización + test |
| R2 | Las notas internas nunca llegan al cliente (ni en panel, ni en email) | Test por cada canal |
| R3 | Los adjuntos solo se descargan con autorización | Almacenamiento privado fuera de `public/`, descarga por controlador + test |
| R4 | Un ticket cerrado no admite respuestas sin reabrir | Test |
| R5 | Cada cambio de estado o asignación queda registrado (quién y cuándo) | Historial + test |
| R6 | Un ticket solo puede llevar un producto asignado a la empresa del solicitante; si la empresa tiene productos, es obligatorio | Validación en servidor (no solo en el desplegable) + test |
| R7 | Solo un supervisor asigna productos a empresas | Política de autorización + test |
| R8 | Solo el administrador de la aplicación crea, desactiva o cambia el rol de empleados internos; nadie puede darse a sí mismo un rol superior | Política de autorización + test |
| R9 | Siempre existe exactamente un administrador activo: no puede eliminarse, desactivarse ni degradarse sin traspasar antes el rol a otro empleado interno | Validación en servidor + test (incluido el intento de saltarlo) |
| R10 | El administrador ve los tickets pero no puede modificarlos ni responder | Política de autorización + test de que todas las acciones de escritura devuelven denegado |

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
2. Empresas y usuarios con roles (cliente: administrador de empresa y usuario; interno: agente, supervisor y administrador de la aplicación); gestión de cuentas internas por el administrador; login. Catálogo de productos y asignación a empresas (pantalla de supervisor, con mockup previo).
3. Crear ticket (con categoría y adjuntos) y verlo en el panel del cliente.
4. Bandeja del agente: ver, asignar, cambiar estado.
5. Hilo de respuestas, notas internas, cierre y reapertura.
6. Notificaciones por email y en el panel.
7. Reglas R1–R10 con tests; comando de recuperación del acceso del administrador, documentado y probado.
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
