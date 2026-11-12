# P1 · Un agente crea un ticket a nombre de un cliente

> Estado (2026-11-12): **IMPLEMENTADO** (plan aprobado y mockups `ticket-nuevo-interno-a/b/c` validados por la usuaria el 2026-11-12). Sin commitear. Cuándo tocar este documento: al responder las preguntas, al cambiar el
> alcance o al implementar. Petición de la usuaria: «un agente puede crear tickets a clientes y asignárselo a otro agente o a él mismo».

## Por qué no es trivial (reglas que se tocan)
- Hoy **solo el cliente abre tickets** (`TicketPolicy::create` = `CompanyUser`/`CompanyAdmin`). Hay que cambiar la matriz de
  `docs/plan/autorizacion.md` Y `TicketActionsTest` (deniega por defecto).
- **R16:** «solo el cliente puede abrir uno nuevo relacionado; agentes y administrador no clonan». Este caso es distinto (ticket
  nuevo sin relación), pero hay que dejarlo escrito para que nadie lo lea como una excepción de R16.
- **R1:** la empresa sale del solicitante (cliente elegido), nunca de un dato del formulario. El agente solo puede elegir clientes de
  empresas **activas** y usuarios **activos**.
- **R11/R13:** el agente solo puede crear en departamentos que atiende (`User::canWorkOn`), y asignar a quien pueda atenderlos.
  Un agente de Contabilidad solo crea de Contabilidad.
- **R10:** el administrador no responde ni cambia estados. **Decidido: SÍ crea y asigna** (ver abajo); hay que reescribir R10 en `CLAUDE.md`.
- **R5:** queda registrado quién lo creó y a quién se asignó (evento de historial).
- **Avisos (`TicketNotifier`):** el cliente (el solicitante) recibe el aviso de que se le abrió un ticket; no se avisa a quien actúa.
  Si se asigna a otro agente, se le avisa a él.

## Propuesta
- Botón «Nuevo ticket» en la bandeja, solo para agentes. Formulario: empresa → usuario solicitante (solo los activos de esa empresa),
  departamento (limitado a los que atiende), categoría, producto (de los de esa empresa, R6/R7), asunto, descripción, adjuntos y
  **asignado a** (por defecto, él mismo; lista limitada a quien pueda atender ese departamento).
- El primer mensaje lo firma **el agente** (no se hace pasar por el cliente) y es público: el cliente lo ve. Hace falta guardar
  `created_by_id` en el ticket (hoy el autor del primer mensaje es el solicitante) y un evento «creado por».
- Reutilizable por el correo entrante: «vincular a un usuario» desde la cuarentena crea el ticket con esta misma acción.
- Se reutiliza `CreateTicket` (con autor distinto del solicitante) y la validación de `StoreTicketRequest`; las validaciones de
  empresa/usuario activos son nuevas. La pantalla usa el estilo ya validado (formulario del cliente + selector de solicitante):
  según la excepción del 2026-11-11 no exigiría mockup nuevo, pero **el selector empresa → usuario es un patrón que aún no existe**,
  así que propongo mockup.

## Decisiones de la usuaria (2026-11-12)
1. **También el administrador** (es el gerente de la empresa: si alguien le llama, debe poder abrir el ticket y asignarlo a quien
   corresponda). Cambia **R10**: el administrador sigue sin responder ni cambiar estados, pero **sí crea** y asigna. Como no atiende
   tickets, la asignación es **obligatoria** (a un agente que atienda ese departamento) y puede elegir cualquier departamento (los ve todos).
2. **La autoría es del agente, «de manera interna»**: queda guardado quién lo creó (`created_by_id`) y lo ve el equipo.
   ⚠ **Interpretación mía, a confirmar:** al cliente no se le enseña el nombre del agente como autor; ve el ticket como suyo
   (su descripción), igual que si lo hubiera abierto él. Si quería que el cliente VIERA la firma del agente, cambia el primer mensaje.
3. **El aviso por correo al cliente se elige al crear** (casilla). ⚠ Por defecto la propongo **sin marcar** (no se manda nada que
   la persona no haya decidido); si prefieres marcada por defecto, dímelo. Cuando se marca, sale con el texto (como el resto de respuestas).
4. **Canal** (teléfono, presencial, correo, otro): campo interno nuevo, obligatorio al crear por un agente. Los tickets que abre el cliente
   o entran por correo llevan su canal (`web` / `email`). No lo ve el cliente (como la prioridad).
5. **Nace siempre «Abierto» y asignado** (por defecto el propio agente; en el administrador se elige).

## Plan (toca más de dos ficheros: se aprueba antes de empezar)
1. Mockup del formulario del agente (selector de solicitante) → validar.
2. Migración: `tickets.created_by_id` (RESTRICT, NOT NULL tras rellenar), `tickets.channel` (CHECK: web, email, phone, in_person, other) y tipo de evento `created_by_staff`; ajustar el modelo de datos.
3. `TicketPolicy::createForCustomer` + matriz + `TicketActionsTest`; `CreateTicket` admite autor ≠ solicitante.
4. Ruta, controlador, `FormRequest` que autoriza antes de validar, vista; botón en la bandeja con `@can`.
5. `TicketNotifier`: aviso al cliente y al agente asignado, nunca a quien actúa.
6. Tests por regla + mutaciones (empresa o usuario de baja, departamento que no atiende, asignado que no puede atenderlo, R1, R13).
7. Recorrido real en la aplicación; `CLAUDE.md`, `ONBOARDING.md` y bitácora.

## Hecho y comprobado (2026-11-12)
- Migración `2026_11_12_100000` (`tickets.created_by_id` NOT NULL + `channel` con CHECK; relleno de los existentes), `TicketChannel`, `TicketPolicy::createForCustomer`,
  `CreateTicket` + `StaffCreation` (una sola acción para ambos casos), `StoreStaffTicketRequest`, `StaffTicketController` (2 pasos), vistas
  `tickets/staff-who` y `staff-create`, enlace «Nuevo ticket» en el menú del equipo, canal y «creado por» en la ficha interna, evento
  `created_by_staff`, aviso `OpenedByStaff` y `TicketNotifier::createdByStaff`.
- `StaffCreatesTicketTest` (12) + habilidad `createForCustomer` en `TicketActionsTest`. 6 mutaciones, TODAS en rojo (policy abierta, acción sin comprobar departamento,
  aviso siempre, evento interno visible al cliente, primer mensaje a nombre del agente, categoría de cualquier departamento). Suite: 592 en verde; Larastan limpio.
- Verificado en la aplicación real (servidor y base `lm_tickets_qa`, no la de desarrollo): el agente crea para María asignado a otro agente con aviso;
  el cliente ve el ticket sin canal ni creador (sí ve quién lo atiende) y recibe el aviso; el cliente recibe 403 en el formulario; la cola envía el aviso al
  cliente y la asignación al agente; capturas de escritorio y móvil del paso 2 correctas.
- Cambio de un test existente (`InboxTest::test_staff_menu_has_no_client_actions`): decía «el equipo no ofrece Nuevo ticket (solo el cliente abre tickets)»;
  la regla cambió por decisión de la usuaria, así que ahora exige el enlace NUEVO del equipo y sigue prohibiendo el del cliente.

## Pendiente / fuera
- **El aviso al cliente no lleva el texto de la descripción** (los mockups decían «con el asunto y la descripción»): el cuerpo por correo es el P1 «correo entrante»,
  que cambiará `TicketNotifier` para TODOS los avisos; hasta entonces lleva número y asunto, como el resto. Dicho a la usuaria.
- Comprobado sin JavaScript solo por construcción (formularios normales); no probado en un navegador con JavaScript desactivado.
