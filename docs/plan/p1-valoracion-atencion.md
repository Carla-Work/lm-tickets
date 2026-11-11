# P1 · Valoración de la atención

> Estado (2026-11-11): **plan y mockups a la espera de validación**. No hay código. Cuándo tocar este documento: al validar o
> cambiar el alcance, y al implementar (marcar lo hecho).

## Alcance propuesto (decisiones de Claude por delegación; la usuaria valida con los mockups)
- **Quién valora:** solo la persona que **abrió** el ticket. No el administrador de su empresa ni el equipo.
- **Cuándo:** con el ticket **resuelto o cerrado** (estado final). No es un mensaje, así que no choca con R4/R15 («un ticket final no
  admite mensajes del cliente»): valorar no escribe en el hilo ni cambia el estado.
- **Qué:** una puntuación de **1 a 5** (1 Muy mala · 2 Mala · 3 Normal · 4 Buena · 5 Excelente) y un comentario **opcional** de hasta 500
  caracteres. **Una sola vez**: no se edita ni se borra (así nadie presiona para cambiarla).
- **Es opcional y no bloquea nada:** no cambia el estado, no mueve la fecha de actividad (no reordena listas) y **no envía avisos ni
  correos** (se puede añadir después, p. ej. avisar al agente si es ≤ 2).
- **Quién la ve:** todo el equipo que ve el ticket (R11, R13), incluido el administrador; el cliente ve la suya (y quienes ya veían ese
  ticket: su administrador de empresa). En el historial queda el evento «valoró la atención: n de 5» (visible para el cliente).
- **Bandeja:** la puntuación junto al estado de los tickets valorados, filtro «Valoración» (Mala 1-2 · Normal 3 · Buena 4-5 · Valorados ·
  Sin valorar) y vista rápida «Mal valorados».
- **Datos personales:** el comentario es texto libre del cliente. Cuando `retention:run` anonimiza un ticket, el **comentario se borra**
  (texto fijo) y la **puntuación se conserva** (estadística, sin datos personales).
- **Fuera:** informes y medias por agente (el siguiente bloque del P1 se apoyará en estos datos), pedir la valoración por correo,
  valorar mensajes sueltos, editar o retirar una valoración.

## Criterio de aceptación (observable)
- Solo quien abrió el ticket puede valorar; el administrador de empresa, el compañero, otra empresa, el equipo y el administrador reciben 403
  (matriz de `TicketActionsTest`, que deniega por defecto) y la acción lo recomprueba con el ticket bloqueado (dos capas).
- Solo con el ticket resuelto o cerrado; una sola vez (la segunda es un error, no una sustitución: gana la primera aunque lleguen a la vez,
  la base lo impide con una clave única).
- Puntuación fuera de 1-5 o comentario de más de 500 caracteres: rechazados por el servidor. El comentario se **escapa** al pintarlo.
- No cambia el estado ni `last_activity_at`, y no envía correo ni aviso.
- Cada empleado la ve solo en los tickets que ve (R13: Contabilidad); el filtro de la bandeja parte de `visibleTo`.
- La anonimización por plazo borra el comentario y conserva la puntuación.
- Sin JavaScript todo funciona (botones de opción normales).

## Plan (toca más de dos ficheros: se aprueba antes de empezar)
1. Mockups `ticket-detalle-cliente-c-valorar`, `ticket-detalle-cliente-d-valorado`, `ticket-detalle-interno-g-valoracion` y
   `bandeja-d-valoracion` → **validar**.
2. Migración `ticket_ratings` (`ticket_id` único con clave foránea RESTRICT, `score` con CHECK 1-5 y NOT NULL, `comment` nulo, `rated_by`
   RESTRICT, `created_at`; solo se añade). Modelo `TicketRating`.
3. Acción `RateTicket` (transacción, permiso recomprobado con el ticket bloqueado, evento `rated` visible al cliente) y `TicketPolicy::rate`.
4. `TicketRatingController` + `StoreRatingRequest` (autoriza antes de validar); ruta `POST /tickets/{ticket}/valoracion`.
5. Vistas: detalle del cliente (formulario o resultado), detalle interno (chip, tarjeta y evento), bandeja (puntuación, filtro y vista
   rápida vía `TicketFilters`).
6. `RunRetention`: borrar el comentario al anonimizar (y su test); matriz de `autorizacion.md`, modelo de datos, `CLAUDE.md`.
7. Tests por regla + mutaciones; recorrido en la aplicación real con cliente, agente y administrador.

## Preguntas abiertas para la usuaria
- ¿Solo quien abrió el ticket, o también el administrador de su empresa?
- ¿Basta con una sola vez y sin poder cambiarla, o prefieres poder corregirla durante unos días?
- ¿Quieres aviso al agente cuando le pongan 1-2? (Propuesta: no por ahora.)
