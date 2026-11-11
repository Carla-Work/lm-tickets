# P1 · Prioridades y etiquetas

> Estado (2026-11-11): **IMPLEMENTADO** (mockups validados por la usuaria el mismo día, que confirmó que el cliente no ve nunca la
> prioridad). Cuándo tocar este documento: al cambiar el alcance.

## Alcance propuesto (decisiones de Claude por delegación; la usuaria valida con los mockups)

### Prioridad
- Cuatro valores fijos: **Baja · Normal · Alta · Urgente**. Todo ticket nuevo entra como **Normal**.
- **Interna:** el cliente NO la elige ni la ve (si la eligiera, todo sería «urgente» y dejaría de servir). No genera avisos.
- Quién la cambia: **cualquier agente que atienda el departamento del ticket** (mismo criterio que mover de departamento, R12), en
  tickets no resueltos ni cerrados. **El administrador no** (R10: ve y reasigna, no gestiona). Cada cambio queda en el historial
  interno del ticket (R5, evento `priority_changed`, no visible para el cliente).
- En la bandeja: etiqueta de prioridad en la fila (solo si no es Normal), filtro «Prioridad» (incluye «Alta o urgente»), orden
  «Prioridad (urgentes primero)» y vista rápida «Urgentes y altas».

### Etiquetas
- **Catálogo cerrado** que gestiona el administrador (Administración › Etiquetas): crear, renombrar, desactivar. Una etiqueta en uso
  **no se borra**, solo se desactiva (como productos, R19): deja de ofrecerse, los tickets que ya la llevan la conservan.
- **Internas:** el cliente nunca las ve (ni en su lista, ni en el detalle, ni en correos o avisos).
- Un ticket lleva **hasta 5**. Las pone y quita cualquier agente que atienda el departamento (no el administrador), en tickets no
  finalizados; cada cambio, al historial interno (evento `tags_changed`).
- En la bandeja: etiquetas bajo el asunto y filtro «Etiqueta».
- Fuera: etiquetas libres escritas por los agentes (acabarían con duplicados «urgente», «Urgente!»…); etiquetas por empresa; reglas
  automáticas (prioridad según producto, etc.).

## Criterio de aceptación (observable)
- Ni la prioridad ni las etiquetas aparecen NUNCA en nada que vea el cliente (lista, detalle, correos, avisos, exportaciones): se
  prueba recorriendo todas esas salidas con un cliente (R2).
- Solo un agente que atiende el departamento puede cambiarlas; el administrador, el cliente y los agentes de otro equipo reciben 403
  (matriz `TicketActionsTest`, que deniega por defecto) y la acción lo recomprueba con el ticket bloqueado (dos capas).
- No se pueden cambiar en tickets resueltos o cerrados.
- Cada cambio deja evento interno (quién, de qué a qué) y los eventos no los ve el cliente.
- Etiqueta desactivada: no se ofrece, pero los tickets que la tienen la conservan y se pueden filtrar por ella.
- No se puede poner una etiqueta inexistente, desactivada o de más de 5; el servidor lo valida (no se fía del formulario).
- Los filtros nuevos solo restringen sobre `visibleTo` (R1, R11, R13) y sin N+1.
- Sin JavaScript todo funciona (formularios normales).

## Plan (toca más de dos ficheros: se aprueba antes de empezar)
1. Mockups `ticket-detalle-interno-f-prioridad-etiquetas`, `bandeja-c-prioridad-etiquetas`, `admin-catalogo-b-etiquetas` → **validar**.
2. Migraciones: `tickets.priority` (texto, `NOT NULL DEFAULT 'normal'`, CHECK con los 4 valores; índice con `status` para la bandeja),
   `tags` (nombre único, `active`) y `ticket_tag` (clave primaria compuesta, claves foráneas RESTRICT). Enum `Priority`.
3. Acciones `ChangePriority` y `ChangeTags` (una transacción, permiso recomprobado con `lockForUpdate`, evento interno).
4. Policy: habilidades `changePriority` y `changeTags` (+ matriz `docs/plan/autorizacion.md` y `TicketActionsTest`).
5. Controladores y vistas: detalle interno (cabecera + «Gestión»), bandeja (fila, filtro, orden, vista rápida) vía `TicketFilters`,
   Administración › Etiquetas (`ManageCatalog` ya gestiona productos y categorías; se amplía o se crea una acción hermana).
6. Tests por regla + mutaciones; recorrido en la aplicación real con el cliente comprobando que no ve nada.
7. Retención: las etiquetas y prioridades no son datos personales; no entran en la purga. Se anotan en el modelo de datos.

## Hecho y comprobado (2026-11-11)
- Migración `2026_11_11_200000` (aplicada en `lm_tickets` y `lm_tickets_testing`), enum `Priority`, modelo/política/factoría de `Tag`, acciones `ChangePriority`, `ChangeTags` y `ManageTags`, `TicketPriorityController` y `Admin/TagController`, vistas (detalle interno, bandeja, Administración › Etiquetas) y filtros en `TicketFilters`.
- `PriorityAndTagsTest` (14) y la matriz de `TicketActionsTest` con las dos habilidades nuevas. 14 mutaciones (cliente que ve la prioridad en lista o detalle, administrador que la cambia, cambiar en tickets finales, agente de otro equipo, que mueva la actividad, evento visible, sin tope, etiquetas desactivadas, evento sin cambios, orden/filtro del cliente, petición sin autorizar, catálogo abierto): todas en rojo.
- Verificado en la aplicación real (base y servidor aparte): admin crea etiquetas, el agente pone prioridad y etiquetas con su evento, la bandeja las pinta y filtra, el cliente NO ve nada en lista, detalle ni avisos, el administrador las ve sin formulario, 403 para administrador y cliente al intentar cambiarlas.

## Decisiones cerradas por la usuaria
- Las cuatro prioridades valen; las etiquetas iniciales propuestas (Cliente con contrato premium, Importación, Pendiente de proveedor, Requiere visita, Facturable) las crea el administrador desde Administración › Etiquetas (no se siembran).
- El cliente **no ve nunca** la prioridad.

## Preguntas abiertas para la usuaria (ya contestadas arriba)
- ¿Cuatro prioridades fijas bastan, o preferís otras (p. ej. sin «Baja»)?
- ¿Etiquetas iniciales? Propuesta de arranque: Cliente con contrato premium · Importación · Pendiente de proveedor · Requiere visita · Facturable.
- ¿Queréis que el cliente vea alguna vez «Urgente» (p. ej. como estado de atención)? Propuesta: no.
