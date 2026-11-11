# P1 · Informes y estadísticas

> Estado (2026-11-11): **IMPLEMENTADO**. La usuaria dispensó el mockup («ya conoces el estilo, no hace falta que hagas más mockups,
> implementa directamente»), así que se construyó con el estilo ya validado y **pide validar el resultado en pantalla**. Cuándo tocar
> este documento: al añadir o redefinir una cifra.

## Alcance (decisiones de Claude por delegación)
- Pantalla **Informes** (menú del equipo, `/informes`): agentes y administrador, cada cual solo con lo que ve (R11, R13). El cliente: 403.
- **Periodo** elegible (atajos de 30 días, 90 días y 12 meses, o desde/hasta). Por defecto, los últimos 30 días; máximo 3 años (más se recorta con aviso).
- **Cifras globales:** creados, resueltos, primera respuesta (media), tiempo hasta resolver (media), valoración media (y cuántas), vivos ahora
  (sin resolver ni cerrar) y vivos sin asignar; vivos ahora con prioridad alta y urgente.
- **Tablas:** por departamento (creados, resueltos, vivos, tiempo hasta resolver, valoración), empresas con más tickets (las 10 primeras) y
  **por agente, solo el administrador** (evaluar a compañeros no es cosa de los agentes).
- **Definiciones** (también en la pantalla, «Cómo se calcula», y en `App\Support\TicketReport`): creados = `created_at` en el periodo;
  resueltos = `resolved_at` en el periodo; primera respuesta = hasta el primer mensaje **público** de un empleado (las notas internas no
  cuentan); resolución = creación → `resolved_at`; valoración = las hechas en el periodo; por agente = quien lo lleva asignado AHORA.
- **Fuera, a propósito:** exportar a CSV (otra vía de sacar datos; se puede pedir), gráficos (haría falta una librería o SVG a mano),
  comparar periodos, informes programados por correo, tiempos por horario laboral (todo es tiempo natural), y atribuir la resolución a
  quien realmente la hizo (no se guarda quién resolvió; hoy es el asignado).

## Criterio de aceptación (observable)
- Cada cifra sale solo de `Ticket::visibleTo`: un agente de Soporte no cuenta Contabilidad ni ve nombres de empresas que solo tienen Contabilidad (R13).
- Las cifras son las que dicen las definiciones: un conjunto de datos controlado con los resultados calculados a mano (`ReportTest`).
- Las notas internas y los mensajes del cliente no cuentan como «primera respuesta».
- Sin datos no hay error ni división por cero (medias nulas, «—»).
- Periodo validado en servidor; el primer y el último día entran enteros.
- Sin consultas por ticket: el coste no crece con el número de tickets.

## Hecho y comprobado (2026-11-11)
- `App\Support\TicketReport`, `ReportController`, `reports/index.blade.php`, puerta `view-reports`, ruta `/informes`, enlace del menú y CSS de las tarjetas.
- `Tickets/ReportTest` (13). 16 mutaciones (sin `visibleTo`, notas internas o mensajes del cliente como respuesta, resueltos por fecha de creación, vivos con resueltos, valoraciones sin periodo, tabla por agente abierta, departamentos sin filtrar, fronteras del periodo, división de horas, puerta abierta, sin recorte): todas en rojo tras añadir los tres casos que se colaban (mensaje del cliente, fronteras y recorte real).
- **Rendimiento** (20 000 tickets, 100 000 mensajes, 2 667 valoraciones): informe completo del administrador ≈ 330-370 ms (112 consultas: crece con los agentes y las empresas listadas, no con los tickets); de un agente ≈ 220 ms.
- Verificado en la aplicación real: administrador y agente, 403 del cliente, periodo cruzado y enorme.
