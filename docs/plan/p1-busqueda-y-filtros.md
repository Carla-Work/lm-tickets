# P1 · Búsqueda y filtros avanzados

> Estado (2026-11-11): **IMPLEMENTADO** (mockups validados por la usuaria el mismo día). Cuándo tocar este documento: al cambiar
> el alcance o añadir un filtro.

## Qué hay hoy (no se rehace)
- **Equipo (bandeja):** buscar por asunto, número, persona o empresa; filtrar por estado, departamento, empresa y asignado.
- **Cliente (Mis tickets):** buscar por asunto o número; filtrar por estado, departamento, producto y «abierto por» (solo administrador de empresa).

## Alcance propuesto (decisión de Claude por delegación; la usuaria valida con los mockups)
1. **Buscar también en el texto de los mensajes** (casilla opcional, desactivada por defecto para no ralentizar).
   - Equipo: mensajes públicos **y notas internas**. Cliente: **solo mensajes públicos** (R2: el cliente no puede enterarse, ni por un acierto de búsqueda, de que existe una nota).
2. **Filtros nuevos:** producto y categoría (equipo), abierto desde / hasta, «sin actividad desde hace N días» (equipo), orden (actividad, apertura, número).
3. **Vistas rápidas** (enlaces que solo ponen filtros): equipo → Todos · Mis tickets · Sin asignar · Sin actividad en 7 días; cliente → Todos · Esperan tu respuesta · Sin cerrar.
4. **Barra de filtros aplicados** con × para quitar uno a uno y «Limpiar todo».

**Fuera, a propósito:** filtros guardados por persona (exigen tabla nueva; se puede pedir después), búsqueda difusa/relevancia (haría falta un motor de búsqueda = dependencia sin aprobar), exportar a CSV (es P1 «Informes»).

## Criterio de aceptación (observable)
- Ningún filtro ni búsqueda devuelve jamás un ticket que la persona no puede ver (R1, R11, R13): todo parte de `Ticket::visibleTo()`.
- Un cliente que busca una palabra que **solo** aparece en una nota interna no obtiene ese ticket (R2). Un agente sí.
- Los filtros se combinan (Y); los valores inválidos se rechazan con mensaje, sin error 500.
- La paginación conserva los filtros; «Limpiar todo» vuelve a la lista completa.
- Sin JavaScript todo funciona (es un GET y un `<details>`).
- Una consulta con todos los filtros activos no hace más consultas de las necesarias (sin N+1).

## Plan (toca más de dos ficheros: se aprueba antes de empezar)
1. Mockups `bandeja-b-filtros-avanzados` y `mis-tickets-c-filtros-avanzados` → **validar**.
2. Un único sitio con la lógica de filtros (`App\Support\TicketFilters`, usado por `InboxController` y `TicketController`) para que equipo y cliente no diverjan; cada controlador le pasa su lista de campos permitidos.
3. Búsqueda en mensajes con `whereExists` sobre `TicketMessage::visibleTo($user)` (mismo ámbito que el detalle: R2 sale gratis).
4. Vistas parciales (barra de filtros aplicados, vistas rápidas) compartidas.
5. Tests: matriz de visibilidad × filtro (R1, R2, R13), combinaciones, valores inválidos, paginación; mutaciones (quitar `visibleTo` de la búsqueda en mensajes debe poner en rojo).
6. Rendimiento: índice por `ticket_messages(ticket_id)` ya existe; medir la búsqueda en mensajes con datos de prueba grandes antes de darla por buena (ver D-10).

## Hecho y medido (2026-11-11)
- `App\Support\TicketFilters` (reglas, aplicación, barra de filtros aplicados, vistas rápidas), usado por `InboxController` y `TicketController`; parciales `partials/vistas-rapidas` y `partials/filtros-aplicados`; CSS al final de `panel.css`.
- Tests: `Tickets/TicketFiltersTest` (13). Se rompió el código de 10 maneras (R2 sin ámbito, casilla ignorada, comodines, campos del equipo para el cliente, «sin actividad» incluyendo resueltos, «abierto por» para cualquiera, fin de día, orden, nombres sin lista, vista siempre actual) y todas se ponen en rojo.
- **Rendimiento** (20 000 tickets, 100 000 mensajes, tablas analizadas): sin filtros 25 ms; buscar en mensajes 145 ms (palabra frecuente) y 167 ms (peor caso, nada casa); cliente 108 ms; todos los filtros 48 ms. Sin FULLTEXT ni índices nuevos. ⚠ **Justo tras una carga masiva, con las estadísticas de MySQL sin recalcular, UNA consulta tardó más de 2 minutos**; no se pudo reproducir después. Si se importan muchos datos de golpe: `ANALYZE TABLE tickets, ticket_messages;`.
- Verificado en la aplicación real (servidor y base aparte): bandeja y «Mis tickets» con filtros, barra, fechas cruzadas con mensaje, paginación, cliente sin bandeja.

## Decisiones tomadas (la usuaria dio el visto bueno al mockup y delegó el resto)
- La búsqueda en mensajes es una casilla desactivada por defecto.
- Vistas rápidas: equipo = Todos · Mis tickets · Sin asignar · Sin actividad en 7 días; cliente = Todos · Esperan tu respuesta · Sin cerrar. «Mis tickets» y «Sin asignar» se limitan a tickets sin resolver ni cerrar.
- «Número» ordena de mayor a menor.
