# P1 · Tickets por correo entrante

> Estado (2026-11-12): **IMPLEMENTADO** (mockups validados al pedir la implementación; sin commitear). ⚠ **La conexión IMAP real NO está probada contra un servidor**: ver «Pendiente». Cuándo tocar este documento: al validar las
> decisiones abiertas, al cambiar el alcance o al implementar (anotar lo hecho al final, como en los otros P1).
> Origen: petición de la usuaria el 2026-11-12. Sustituye la exclusión «email entrante (candidato a P1)» de
> `00-encuadre.md`, que se actualizará al aprobar.

## Qué se pide (palabras de la usuaria, 2026-11-12)
- Lo que llegue a **aplicaciones@lingmarco.es** va al departamento de **Desarrollo y Diseño**; lo de **soporte@lingmarco.es**, a **Soporte**.
- Por **palabras clave** del correo se asigna el **producto** (p. ej. «fichaje» → oneCOP). Las palabras se gestionan en una **pantalla de administración**.
- Si la empresa o el cliente **no existen**, el correo **no se asigna**: el administrador decide si es correcto, spam u otra cosa.
- No basta el correo: se **valida contra la base de datos** (usuario registrado o dominio de una empresa registrada).
- Método: **IMAP**.
- Cuarentena: **30 días** para el posible spam y **90** para los pendientes.
- **El cliente no entra al panel**: la respuesta del equipo le llega **por correo electrónico con su texto**, aparezca o no en el panel.
  El agente decide por mensaje si se envía (casilla), para poder rellenar la ficha internamente antes.

## Reglas que cambian o se tocan (decisión explícita de la usuaria, a anotar en `CLAUDE.md` al implementar)
- **Aviso por correo con CUERPO.** Hoy «ni el correo ni la campana llevan el cuerpo de los mensajes (el correo viaja en claro)».
  Se hace una excepción **solo para tickets nacidos por correo** y solo para mensajes públicos con la casilla marcada. Las notas
  internas siguen sin salir nunca (R2). Pendiente confirmar si la excepción es solo para esos tickets o para todos (ver P1 abajo).
- **R10:** el administrador no responde. La cuarentena le deja **clasificar** correos, no contestar.
- **R18:** nunca nace una cuenta por un correo. Invitar al remitente pasa por `InviteUser`, a decisión del administrador.
- **R1/R13:** el departamento sale del buzón; `comercial@` crea tickets de Contabilidad, que solo ve ese equipo y el administrador
  (lo ve y reasigna). En cuarentena, el correo de `comercial@` solo puede abrirlo el administrador, nunca un agente de otro departamento.

## Diseño propuesto
**Lectura.** Comando programado (`schedule:run`) que abre cada buzón por IMAP. Cada buzón tiene un departamento fijo en una
configuración nueva (`config/inbound.php`). Cada correo se procesa **una sola vez** (`Message-ID` único); los autorrespondedores y
rebotes (`Auto-Submitted`, `Precedence`, remitente vacío) se ignoran para que no haya bucles.

**Filtro de entrada (decidido el 2026-11-12: no todo correo es una incidencia).** Cada correo cae en UNO de tres sitios:
1. **Respuesta en un hilo:** el correo responde a uno nuestro (cabeceras `In-Reply-To`/`References` que apuntan al `Message-ID` de un
   correo que enviamos) y el remitente es el solicitante y pasa SPF/DKIM → se añade como mensaje de ese ticket (R14; ticket final →
   rebote, ver arriba). **Aunque sea corto («gracias»)**: es parte del hilo.
1b. **Ticket nuevo:** correo que NO responde a un hilo, de un usuario **activo** de empresa **activa** que pasa SPF/DKIM, **y que
   parece una incidencia** (decidido: «si no es una incidencia, no crea ticket»): contiene palabras clave de producto o de
   incidencia (editables en la misma pantalla de palabras clave: «error», «no funciona», «fallo», «no puedo»…). Con las mismas
   validaciones del formulario. Si no parece una incidencia → cuarentena «pendiente» (con el remitente ya identificado), para que
   no se pierda un aviso real sin palabras reconocibles.
2. **Cuarentena «pendiente» (90 días):** el remitente es desconocido pero hay una **pista**: su dominio está registrado en una empresa
   activa (se sugiere la empresa) o el texto contiene palabras clave de producto. El administrador decide: invitar (`InviteUser`),
   vincular a un usuario, o marcar como spam. Nunca se crea ticket solo por el dominio.
3. **«Posible spam» (30 días):** sin cliente, sin dominio y sin palabras clave. Pestaña aparte, para revisarla sin ruido.
- Correos cuyo remitente es `@lingmarco.es` (compañeros, avisos del sistema): no crean ticket ni van a cuarentena; se ignoran y se
  cuentan en el resumen del comando. ⚠ Hipótesis mía, a confirmar.
- **Persona nombrada.** Si el correo va (Para/CC) a un agente que puede atender ese departamento (`User::canWorkOn`), el ticket nace
  **asignado a esa persona** (evento registrado, R5). Si no hay ninguno, queda sin asignar en la bandeja. ⚠ Interpretación mía de
  «los correos a los compañeros se les asignan a ellos directamente», a confirmar.
- Campo nuevo: dominios de la empresa. **Lista negra de dominios gratuitos** (gmail, hotmail…) imposibles de registrar.
- **SPF/DKIM/DMARC** (cabecera `Authentication-Results` del servidor): si el correo no los pasa, va a cuarentena aunque la dirección
  exista, porque el «De» se falsifica. A confirmar que IONOS ofrece esa cabecera.

**Producto.** Tabla de palabras clave por producto (pantalla en «Productos y categorías»). Se busca en asunto y cuerpo. Solo vale un
producto **que la empresa tenga** (R6/R7); sin coincidencia o con varias → sin producto. Nunca se adivina.

**Categoría.** Obligatoria (R6): una categoría por defecto en cada departamento («Sin clasificar»). El agente la corrige con el
cambio de departamento ya existente (R12).

**Contenido.** HTML → texto plano, sin citas ni firmas cuando se puedan detectar, siempre escapado al pintarlo. Los adjuntos pasan por
`AllowedAttachment` (lista cerrada, por contenido) y el límite de 25 MB; los que no pasan se anotan en el ticket.

**Cuarentena** (`inbound_emails`, solo se añade; RESTRICT): remitente, asunto, cuerpo, motivo, estado (pendiente / spam / vinculado),
quién decidió y cuándo. Acciones del administrador: **spam**, **vincular a un usuario existente** (crea el ticket) o **invitar**.
Retención: spam 30 días, pendientes 90 (se suma a `retention:run`). Registrado con `Audit::record()`.

**Respuesta al cliente por correo.** En un ticket nacido por correo, el formulario de respuesta lleva la casilla «enviar al cliente
por correo» (decide el agente; sin marcar es un mensaje público que el cliente ve solo en el panel, o una nota interna si ya existe
esa opción). Va a la dirección del solicitante, nunca a compañeros. Asunto con el número de ticket; cabeceras `In-Reply-To`/`References`.

**Respuesta del cliente por correo (necesaria si no entra al panel).** Un correo cuyo hilo corresponde a un ticket (por cabeceras, no
solo por asunto) se añade como mensaje, si el remitente es el solicitante. R4/R15: con el ticket resuelto o cerrado no se añade; se
responde que debe abrir uno nuevo (o va a cuarentena, a decidir). R14 se mantiene.

## Fuera de esta tarea
Pasar a resuelto / valorar por correo; reglas automáticas por contenido más allá de producto; antivirus (otro punto del P1).

## Decidido por la usuaria (2026-11-12)
- **Buzones: tres, todos COMPARTIDOS con personas**: `aplicaciones@` (→ Desarrollo y Diseño; lo reciben también el administrador y el
  equipo de Desarrollo y Diseño), `soporte@` (→ Soporte) y `comercial@` (→ Contabilidad, departamento `other`, R13). Los agentes
  también reciben correos personales: esos buzones **no se leen** (solo los tres compartidos).
  - Consecuencia: el programa **no puede cambiar nada que vean las personas**. Se lee con `BODY.PEEK` (sin marcar como leído) y no se
    borra ni se mueve nada. Qué correos ya se procesaron se apunta en nuestra base (carpeta + UID + `Message-ID`), no en el buzón.
  - Los correos que no son de clientes (avisos del propio sistema, enlaces de recuperación del administrador, personas internas) caen
    en cuarentena como «no es de un cliente» y se pueden descartar con un clic; hay que filtrar de antemano los remitentes
    `@lingmarco.es` para que no llenen la cuarentena (a confirmar).
- **Cliente responde a un ticket resuelto o cerrado: rebote automático** («este ticket está cerrado, abre uno nuevo»), solo si el
  remitente pasó SPF/DKIM **y** es el solicitante (si no, sería reenviar correo basura a una dirección falsificada); como mucho uno
  por ticket y día, para evitar bucles. Nada se añade al ticket (R4/R15).
- **Adjuntos en AMBOS sentidos** (capturas, imágenes, PDF…): entrantes con la misma lista cerrada (`config/access.php`; ya incluye
  png, jpg, gif, webp y pdf) y los límites actuales; salientes: los que el agente adjunta a ese mensaje viajan como adjuntos del
  correo. Un correo muy grande puede ser rechazado por el servidor del cliente: **tope de tamaño total del correo saliente** (a fijar,
  propuesta 10 MB) y el formulario avisa al agente antes de enviar si lo supera. R3 sigue: en el panel se descargan solo con policy.
- **IMAP aprobado** (la usuaria autoriza buscar la librería; la instalación concreta se confirma con el nombre abajo).

## Librería IMAP (comprobado el 2026-11-12, `composer show -a`)
- Propuesta: **`webklex/php-imap` 6.2.0**, licencia MIT, PHP ^8.0.2. **No necesita la extensión `imap` de PHP** (cliente propio), que sí
  está en este portátil pero puede faltar en IONOS (la extensión `imap` salió del núcleo de PHP). Requiere `ext-zip`, `ext-iconv`,
  `ext-mbstring`, `ext-fileinfo`, `ext-openssl`, `ext-libxml`: **hay que comprobar `zip` en IONOS** (`preflight:check`).
- Alternativa sin dependencia nueva: la extensión `imap` nativa, solo si IONOS la tiene (por comprobar). Es más frágil de cara al futuro.
- **No instalada.** Falta el visto bueno al nombre concreto (regla de dependencias).

## Decisiones cerradas (2026-11-12, segunda tanda)
- Cuerpo por correo en **todos** los tickets (no solo los nacidos por correo); notas internas nunca (R2). ⚠ Esto afecta a
  `TicketNotifier` y a la regla «el correo no lleva el cuerpo»: se reescribe en `CLAUDE.md`.
- Buzones en IONOS con IMAP (no verificado por mí; `preflight:check` lo comprobará). `webklex/php-imap` aprobada (falta `ext-zip` en IONOS).
- Tope del correo saliente: **10 MB** en total; el formulario avisa antes de enviar.

## Decisiones abiertas (a confirmar con la usuaria)
1. ⚠ La usuaria copió la pregunta sobre la **interpretación del filtro y de asignar al compañero nombrado** sin respuesta escrita; se
   toma como aceptada (aprobó el plan) y se corrige en los mockups si no era así.
2. Respondido: «gracias» dentro de un hilo = mensaje; fuera de un hilo y sin pinta de incidencia = no hay ticket. Queda por validar
   mi propuesta de que ese correo vaya a cuarentena «pendiente» (y no se descarte) y la **lista inicial de palabras de incidencia**.
3. **Plan APROBADO por la usuaria (2026-11-12): se pasa a los mockups** (cuarentena, palabras clave, casilla de envío).

## Plan (toca más de dos ficheros: se aprueba antes de empezar)
1. Mockups: cuarentena (lista y detalle), palabras clave en «Productos y categorías», casilla en el detalle interno → **validar**.
2. Migraciones: dominios de empresa, palabras clave, `inbound_emails`, categorías por defecto, columna de origen/Message-ID en tickets y mensajes.
3. Dependencia IMAP aprobada; `config/inbound.php`; comando de lectura; clasificador (remitente, producto, categoría).
4. Cuarentena: controlador, policy, vistas, matriz de `autorizacion.md` y `TicketActionsTest`.
5. Correo saliente con cuerpo, casilla en `ReplyToTicket`/`TicketNotifier`; respuesta entrante al hilo.
6. Retención, auditoría, `preflight:check` (buzón alcanzable), `docs/plan/produccion.md`, `CLAUDE.md`, modelo de datos.
7. Tests por regla + mutaciones (suplantación, dominio sin usuario, nota interna nunca por correo, duplicados, bucles, producto ajeno).
8. Recorrido real contra un buzón de pruebas.

## Mockups (hechos el 2026-11-12, pendientes de validar; `docs/plan/mockups/`)
- `correo-cuarentena-a-lista` — «Correo entrante» del administrador: pendientes (90 días) y posible spam (30 días), con el porqué de cada correo.
- `correo-cuarentena-b-detalle` — un correo pendiente: comprobaciones SPF/DKIM, texto plano, adjuntos; invitar, crear ticket o marcar spam.
- `admin-catalogo-c-palabras-clave` — palabras clave por producto y palabras de incidencia, dentro de «Productos y categorías».
- `ticket-detalle-interno-h-respuesta-por-correo` — casilla «Enviar por correo» (marcada por defecto), medidor de adjuntos y canal/producto del ticket.
- `ticket-detalle-interno-i-respuesta-adjuntos-grandes` — el mismo formulario con más de 10 MB: botón bloqueado y qué hacer.
- `correo-saliente-a-respuesta-y-rebote` — cómo ve el cliente la respuesta (con adjunto y cita) y el rebote de «ticket cerrado». Textos: primera propuesta.
- Decisiones de diseño mías (a confirmar): entrada «Correo entrante» en el menú del administrador, con contador; «Revisar» en vez de abrir al hacer clic en la fila;
  «Marcar spam» no borra (se purga por plazo); desde la cuarentena se invita o se crea ticket, nunca se responde al remitente (R10).

## Hecho y comprobado (2026-11-12)
- **Datos** (`2026_11_12_200000`): `company_domains`, `product_keywords`, `incident_words`, `inbound_emails` (+ CHECK de estados), `inbound_email_attachments`, `ticket_messages.email_message_id/via_email/emailed_at`, `categories.is_default` («Sin clasificar», una por departamento).
- **Lectura y filtro:** `ImapMailboxClient` (webklex/php-imap 6.2.0, `leaveUnread`), `ProcessIncomingEmail` (el filtro), `EmailAuthentication` (SPF/DKIM de la PRIMERA cabecera), `KeywordMatcher`, `inbound:fetch` (programado cada minuto solo con `INBOUND_ENABLED`).
- **Salida:** `TicketMailer` + `TicketReplyMail` (texto, adjuntos, Message-ID, In-Reply-To/References, tope 10 MB) y `ClosedTicketBounceMail`; casilla «Enviar por correo» en «Responder» (marcada por defecto); sin casilla solo campana; `MailLogger` sin cuerpo.
- **Administrador:** «Correo entrante» (lista, detalle, spam / no es spam, invitar con `InviteUser`, crear ticket con `CreateTicket`+`StaffCreation`+`EmailOrigin`), palabras clave y de incidencia en «Productos y categorías», dominios en el detalle de empresa, `InboundEmailPolicy`.
- **Retención** (`retention:run`): spam 30 días, el resto 90, con sus ficheros. `preflight:check` comprueba buzones, extensiones y SPF/DKIM.
- **Tests:** `tests/Feature/Inbound/*` (filtro 18, lectura real de correos 6, palabras/SPF 4, comando 5, salida 9, cuarentena 14, retención 1). 12 mutaciones: 11 en rojo y una equivalente (sin la comprobación de cabecera vacía, la regla siguiente ya devuelve falso). Suite entera y Larastan limpios.
- **Verificado en la aplicación real** (servidor y base `lm_tickets_qa`): correos alimentados por el mismo código que usará IMAP → ticket con adjunto, pendientes (dominio, «Gracias», sin SPF), spam, administrador crea ticket y lo asigna, respuesta con correo (`Message-Id` guardado = cabecera enviada; registro sin cuerpo), respuesta del cliente entra en el hilo (con el texto citado quitado) y rebote al ticket cerrado. Capturas de escritorio de las pantallas.

## Decisiones tomadas al implementar (a confirmar con la usuaria)
- Remitente desconocido: **pendiente** si hay pista de dominio, palabra de producto o palabra de incidencia (el mockup lo mostraba así; el plan decía solo dominio/producto); si no, posible spam.
- Un cliente conocido que **no pasa SPF/DKIM** va a pendientes marcado «posible suplantación» aunque sea una respuesta a un hilo.
- «Sin clasificar» es una categoría interna (`is_default`): no se ofrece en ningún formulario ni al mover de departamento.
- Al crear un ticket desde la cuarentena, la casilla «Avisar a la persona» viene marcada (su correo es ya un ticket y espera respuesta).
- Los dominios de empresa se gestionan en el detalle de empresa **sin mockup propio** (estilo ya validado, excepción del 2026-11-11): pedir validar el aspecto.
- Se quita el texto citado de las respuestas por correo (líneas «>» y desde «El … escribió:»), con una heurística sencilla; las firmas no se recortan.

## Pendiente / limitaciones (dichas a la usuaria)
- ⚠ **No probado contra un servidor IMAP real** ni con el SMTP de IONOS (que permita enviar desde aplicaciones@/soporte@/comercial@) ni con la cabecera `Authentication-Results` de IONOS (si no la añade, todo cae en cuarentena). Probar con `inbound:fetch --mailbox=…` y un correo de prueba.
- **No hay forma de cambiar la categoría dentro del MISMO departamento** (R12 solo mueve de departamento): un ticket «Sin clasificar» seguirá así salvo que se mueva de departamento. Decidir si se añade «Cambiar categoría».
- Sin copia de los correos entrantes en el buzón (a propósito: no se toca); si falla la base entre leer y guardar, se reintenta (Message-ID único).
- Los adjuntos de la cuarentena no se descargan desde la web (R3): pasan al ticket al crearlo.
- La respuesta del cliente por correo con un ticket «Pendiente de cliente» lo pasa a «En curso» (R14); un correo de otra persona de su empresa (no el solicitante) va a la cuarentena.
