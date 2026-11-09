# Autorización: quién puede hacer qué

Estado: **paso 3 de la Fase 2, implementado y probado (2026-11-09)**; las propuestas siguen pendientes de validar. Este documento es la matriz que implementan las *policies* de
`app/Policies/` y los ámbitos `visibleTo` de los modelos. Si cambia una regla, cambia aquí **y** en
el test correspondiente. Lo marcado **(propuesta)** lo ha elegido Claude y espera validación de la
usuaria; todo lo demás está decidido (reglas R1-R21 del encuadre).

## Principios

1. **Mínimo privilegio por defecto.** Lo que no está permitido aquí, se deniega. Ante una duda sin
   decidir se implementa lo MÁS restrictivo (se puede abrir después; cerrar tras una fuga no sirve).
2. **Dos defensas que deben coincidir.** Las listas se filtran con el ámbito `Ticket::visibleTo($user)`
   (para que un listado, una búsqueda o un contador nunca toquen lo que no se debe ver) y cada acción
   se autoriza con su policy. Un test (`TicketVisibilityTest`) comprueba que **ambas dan el mismo
   resultado** en todas las combinaciones de rol, departamento y propietario.
3. **Cuentas dadas de baja, o de una empresa de baja, no pueden nada** (`Gate::before`), aunque tengan
   una sesión abierta.
4. **Quien escribe en un ticket, lo atiende.** Un agente no responde en un ticket ajeno sin asignárselo
   antes (decidido). «Ajeno» y «sin asignar» cuentan igual.

## Quién ve qué tickets (R1, R11, R13)

| Rol | Tickets visibles |
|---|---|
| Usuario de empresa | Solo los que **abrió él** (y de su empresa). |
| Administrador de empresa | **Todos** los de su empresa. |
| Agente | **Todos salvo los de «Otros»** (de cualquier departamento y de cualquier empresa). |
| Administrador | **Todos**, incluidos los de «Otros». |

## Notas internas y datos internos (R2)

Los mensajes `internal`, los eventos con `visible_to_customer = false` y los adjuntos de notas
internas los ven **solo** el agente (en los tickets que ve) y el administrador. **Ningún rol de cliente
los ve por ninguna vía**: ni en la lista, ni en el detalle, ni en un correo, ni en un aviso, ni por un
adjunto. Se garantiza con los ámbitos `visibleTo` de `TicketMessage`, `TicketEvent` y `Attachment`.

## Acciones sobre un ticket

«Gestiona» = el que lo atiende: el **agente asignado** (ticket que no es de «Otros») o el
**administrador** (solo en tickets de «Otros»). «Final» = Resuelto o Cerrado.

| Acción | Usuario de empresa | Admin. de empresa | Agente | Administrador |
|---|---|---|---|---|
| Abrir un ticket (R6 lo valida el paso 4) | Sí | Sí | No | No |
| **Responder al cliente** (mensaje público) | Solo el suyo, **no final** (R4) | Solo el suyo, no final **(propuesta)** | Si **lo gestiona** y no está Cerrado | Si es de «Otros» y no está Cerrado |
| **Nota interna** | No | No | **Cualquier ticket que ve**, no Cerrado (decidido) | Solo «Otros», no Cerrado |
| Marcar como **resuelto** (explicando cómo, R16) | Solo el suyo, no final | Solo el suyo, no final | Si lo gestiona y no es final | Si es de «Otros» y no es final |
| Cambiar el **estado** (abierto, en curso, pendiente) | No | No | Si lo gestiona y no es final | Solo «Otros», no final |
| **Cerrar** (Resuelto → Cerrado) | No | No | Si lo gestiona | Solo «Otros» |
| **Reasignar** (R11) | No | No | Cualquier ticket que ve, no final | Cualquiera **salvo «Otros»**, no final (R10) |
| **Cambiar el departamento** (R12) | No | No | Cualquier ticket que ve, no final | **Solo «Otros»**, no final |
| Abrir un ticket **relacionado** de uno final | El suyo | Cualquiera de su empresa | No | No |
| Ver notas internas, historial interno | No | No | Sí | Sí |

Notas:
- **«Otros» no tiene asignado:** lo lleva el administrador. Nadie puede asignarlo (los agentes ni lo ven).
- **El administrador, fuera de «Otros», es de solo lectura** (R10) **salvo reasignar**.
- Un agente que cambia un ticket a «Otros» **deja de verlo** (R13); el administrador pasa a gestionarlo.
- En **Resuelto** se puede escribir (mensajes del equipo, notas) y cerrar, pero **no** reasignar ni cambiar
  de departamento (decidido). El cliente no puede escribir (R4).
- **Nadie reabre** (R15): ningún estado vuelve atrás (ver «Transiciones»).

## Transiciones de estado (R14, R15) — propuesta

| Desde | Hacia (permitido) |
|---|---|
| Abierto | En curso · Pendiente de cliente · Resuelto |
| En curso | Pendiente de cliente · Resuelto |
| Pendiente de cliente | En curso · Resuelto |
| Resuelto | **Cerrado** (solo) |
| Cerrado | nada |

«Pendiente de cliente → En curso» también ocurre solo cuando el cliente responde (R14, paso 4).
**Sin decidir:** si se puede pasar de «En curso» otra vez a «Abierto» (aquí no: «Abierto» es solo el
estado inicial).

## Adjuntos (R3)

Se descargan solo si el usuario **puede ver el ticket Y puede ver el mensaje** al que pertenece el
adjunto. Se guardan en un disco **privado** fuera de `public/`; nunca hay una URL pública. La
descarga irá por un controlador que llama a `AttachmentPolicy::download` (paso 4).

## Administración

| Acción | Usuario de empresa | Admin. de empresa | Agente | Administrador |
|---|---|---|---|---|
| Ver empresas, ver su empresa | Su empresa (solo lectura) | Su empresa (solo lectura) | No | Todas |
| Crear, editar, dar de baja empresas | No | No | No | Sí (R8) |
| **Asignar productos** a una empresa (R7) | No | No | No | **Sí** |
| Editar catálogo de productos y categorías | No | No | No | Sí |
| Invitar usuarios (R8, R18) | No | **A su empresa**, como usuario o admin. de empresa | No | Empleados (agentes) y el primer usuario de una empresa |
| Dar de baja a un usuario | No | **De su empresa**, no a sí mismo | No | A cualquiera **salvo a sí mismo** (R9) |
| Cambiar el rol usuario ↔ admin. de empresa | No | De su empresa, no el suyo | No | Sí |
| Cambiar el equipo de un agente | No | No | No | Sí |
| **Traspasar el rol de administrador** (R9) | No | No | No | **Sí** |

- El administrador **no puede darse de baja ni degradarse a sí mismo**: antes traspasa el rol (R9).
- Un administrador de empresa **nunca** toca nada de otra empresa (R1).
- **(propuesta)** El administrador de empresa **no puede cambiar los productos** de su empresa: solo los ve.
  Si necesita uno nuevo, abre un ticket en «Otros».

## Sin decidir (se ha implementado lo más restrictivo)

1. **¿El administrador de empresa puede responder en tickets de sus compañeros?** Hoy: **no**, solo leer
   (la propuesta de la tabla de decisiones abiertas, nº 6).
2. **¿Quién cierra un ticket Resuelto?** Hoy: **el agente que lo lleva** (la usuaria dijo «un agente»).
3. **«Abierto» como estado al que volver:** hoy no.
4. **¿Puede un agente crear un ticket en nombre de un cliente?** Hoy: no (los tickets los abre el cliente).
