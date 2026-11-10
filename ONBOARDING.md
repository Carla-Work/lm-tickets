# ONBOARDING — estado del proyecto

Última actualización: 2026-11-09. Las reglas están en `CLAUDE.md`; el porqué, en `docs/plan/`.

## Estado

**Fase 1 (andamiaje) casi cerrada.** Encuadre aprobado. No hay funcionalidad de negocio todavía.

### Hecho
- Repositorio `lm-tickets` en `main`, con encuadre, bitácora y esqueleto Laravel 13.40.
- MySQL como base de datos (`lm_tickets` y `lm_tickets_testing`); migraciones del esqueleto aplicadas.
- CSS propio en `public/css/app.css`; sin Tailwind, Vite ni Node.
- Comandos de calidad: `composer format`, `format:check`, `analyse`, `check`.
- Test propio `ConfiguracionTest` (idioma español); visto fallar al romper la configuración.
- Larastan nivel 5 limpio. Tests contra MySQL.

### Verificado
- `composer check`: Pint, Larastan y tests en verde.
- `php artisan serve`: portada y `css/app.css` responden HTTP 200.
- `composer dev` (servidor + cola + logs, sin Node): portada 200 en el puerto que imprime; sin procesos huérfanos.
- Copia de seguridad: copia → restauración en una base aparte → comparación tabla a tabla y fila marcada idéntica (2026-11-09).

### Sin verificar
Nada de la Fase 1.

### Fase 1: cerrada (2026-11-09)
Pendientes que quedan fuera de la fase: zona horaria de presentación (hoy UTC), retirar la copia duplicada de `docs/` en `/Volumes/Proyectos Lingmarco/Publico/lingmarco/` (decide la usuaria) y subir a GitHub (lo ejecuta la usuaria).

### Fase 2: rebanada vertical CERRADA (2026-11-10)
Pasos: 0 cierre de Fase 1 · 1 modelo de datos · 2 acceso (entrar, bloqueo, recuperar, invitaciones, 2FA opcional del administrador) · 3 autorización · 4 flujo del ticket (4a abrir y ver · 4b bandeja · 4c responder, notas, estados, asignar y departamento · 4d adjuntos y relacionados) · 5 avisos por correo y campana (`36fa9f2`) · **6 test de integración del flujo completo (`tests/Feature/CriticalFlowTest.php`, commit `6fa0b13`, 6 tests; 376 en total, todos en verde)**. Todo **hecho**.

**Qué hay ya, de punta a punta:** el cliente inicia sesión, abre un ticket con adjuntos, el equipo del departamento recibe aviso, un agente lo ve en la bandeja, se lo asigna, lo atiende, responde y deja notas internas, el cliente recibe aviso en la campana y por correo, responde (R14), lo marca resuelto explicando cómo (R16), un agente lo cierra, nadie lo reabre (R15) y el cliente abre uno nuevo relacionado. «Otros» solo lo lleva el administrador (R13). Todas las reglas R1–R21 con test.

**Fase 3 (2026-11-10, sin commitear):** hechas Administración › Empresas, Productos y categorías, Usuarios (con traspaso del rol), «Mi empresa» y «Mis datos» (datos y contraseña). Detalle y decisiones por confirmar en la bitácora del día. Sigue pendiente de lo siguiente.

**Lo que faltaba del P0 al cerrar la Fase 2 (lo ya hecho figura arriba):** pantallas del administrador (Empresas, Productos y categorías, Usuarios del equipo con el traspaso del rol); «Mi empresa» y usuarios del administrador de empresa; «Mis datos» (con activar/desactivar el 2FA y regenerar códigos); comando de recuperación del acceso del administrador, documentado y probado; plan de conservación de datos implementado; rol de Ruyman Sosa (Gerente). Hoy esas cuentas y empresas se crean a mano en la base de datos. Para producción: trabajador de colas, remitente real de correo y la Fase 4 (endurecimiento).

**Pendiente de validar por la usuaria:** el selector de categoría del cambio de departamento (el mockup no lo dibuja), la correspondencia departamento–equipo de los avisos (Páginas web → Desarrollo y Diseño) y el resto de «Decisiones abiertas».

Decidido para el acceso: 2FA solo del administrador, sin «Recordarme», bloqueo a los 5 intentos, enlace de 60 min, 12 caracteres.
**«Registros» del administrador (2026-11-10, sin mockup a petición):** registro de correos, auditoría y copias de seguridad en `/administracion/correos`, `/auditoria` y `/copias` (solo el administrador, habilidad `view-records`). Implementado y probado con tests (`tests/Feature/Records`); **pendiente de recorrer en la aplicación real**. Sin copia automática programada ni purga por plazo; la auditoría aún no cubre altas/bajas de usuarios ni el traspaso del rol (esas pantallas no existen todavía).

**2FA del administrador (OPCIONAL desde 2026-11-09):** sin activarlo entra con contraseña; hoy **no se puede activar desde la interfaz** (falta mockup y pantalla en «Mis datos»). Implementado (TOTP a mano, sin librerías, con códigos de recuperación). Pendiente: **regenerar los códigos** (mockups `mis-datos-c` y `mis-datos-d`, **pendientes de validar**; sin implementar) y el traspaso del rol, que deberá llamar a `User::clearTwoFactor()`.

### Decisiones abiertas
varios administradores por empresa, «Mi empresa» del usuario normal, confirmación al dar de baja una empresa, plazos de conservación definitivos, plan de IONOS y cómo encaja el panel en la web pública.

### Mockups
- `docs/plan/mockups/nuevo-ticket-a-departamentos-tarjetas.html`: pantalla «Nuevo ticket». **Validado 2026-11-09.** Departamentos (Soporte, Aplicaciones, Páginas web, Otros) como campo del ticket, categoría dependiente del departamento, producto de la empresa.
- `docs/plan/mockups/mis-tickets-a-lista-tabla.html`: lista de tickets del usuario. **Validado 2026-11-09.**
- `docs/plan/mockups/bandeja-a-lista-tabla.html`: bandeja del panel interno, con columna «Abierto por» (persona + empresa) y «Asignado a». **Validado 2026-11-09.**
- `docs/plan/mockups/ticket-detalle-cliente-a-hilo.html`: detalle del ticket del cliente (estado «Pendiente de tu respuesta»). **Validado 2026-11-09.**
- `docs/plan/mockups/ticket-detalle-interno-a-hilo.html`: detalle interno (agente que lleva el ticket). **Validado 2026-11-09.**
- `docs/plan/mockups/ticket-detalle-cliente-b-resuelto.html`: ticket resuelto, sin reabrir, con «Abrir un ticket nuevo» (R4). **Validado 2026-11-09.**
- `docs/plan/mockups/nuevo-ticket-b-relacionado.html`: nuevo ticket relacionado con uno resuelto. **Validado 2026-11-09.**
- `docs/plan/mockups/ticket-detalle-interno-c-resuelto.html`: ticket resuelto no cerrado, vista interna. **Validado 2026-11-09.**
- `docs/plan/mockups/ticket-detalle-interno-d-…` y `…-e-…`: alternativas del formulario del cambio de departamento. **Implementada E** (un selector de tipo agrupado por departamento; el departamento sale de la categoría) el 2026-11-10 por indicación «implementa» de la usuaria, que no eligió expresamente: **E confirmada por la usuaria el 2026-11-10** («un selector»). D queda como referencia descartada.
- `docs/plan/mockups/ticket-detalle-interno-b-ajeno.html`: agente que no lleva el ticket, con «Asignármelo». **Validado 2026-11-09.**
- Acceso: `acceso-a-entrar`, `acceso-b-entrar-error`, `acceso-c-recuperar`, `acceso-d-recuperar-enviado`, `acceso-e-nueva-contrasena`. **Validado 2026-11-09.**
- Administrador: `admin-empresas-a-lista`, `admin-empresa-b-detalle` (asigna productos), `admin-empresa-c-nueva`, `admin-usuarios-a-empleados` (con traspaso del rol), `admin-catalogo-a-productos-categorias`. **Validado 2026-11-09.**
- Cliente: `cliente-mi-empresa-a-administrador`, `mis-tickets-b-administrador-empresa`, `mis-datos-a-cliente`, `avisos-a-cliente`. **Validado 2026-11-09.**
- Interno: `mis-datos-b-interno`, `avisos-b-interno`. **Validado 2026-11-09.**
- **Ya hay mockup de todas las pantallas previstas.** Falta que la usuaria los valide; las dudas pendientes están en el encuadre, sección «Acceso, administración y panel del cliente».
- Sin dibujar, por no haberse pedido: ticket recién creado sin respuestas, enlace caducado, estados vacíos de las listas, vista del usuario normal en «Mi empresa».
- Decidido: el cierre lo hace un agente; nadie reabre (se clona/crea uno relacionado); el cliente marca «resuelto» directo pero explicando cómo; al responder el cliente el estado pasa solo a «En curso».
- Decidido: solo el cliente clona; el agente también explica cómo resolvió (R16); en «Resuelto» el agente puede escribir mensajes y notas.
- Abierto: si en «Cerrado» nadie escribe (supuesto); si reasignar y cambiar departamento siguen activos en «Resuelto».
- Decidido: el agente ve todos los tickets; hay dos equipos internos (Soporte y Desarrollo y Diseño) y cualquier agente puede asignar a alguien del otro equipo (R11).
- Decidido 2026-11-09: «Otros» solo lo ve y responde el administrador (R13); el agente puede corregir el departamento y se avisa al cliente (R12); el supervisor se fusiona con el administrador; el agente no responde en tickets ajenos sin asignárselos.
- Confirmado: supervisor = administrador; el administrador también reasigna; sin suplente (avisa él a los clientes si se ausenta); puede reclasificar «Otros».
- Abierto: texto de visibilidad en «Nuevo ticket» al elegir «Otros»; qué pasa con la persona asignada cuando un cambio de departamento cambia de equipo; si se quiere un aviso de ausencia en el panel del cliente (opcional).

### Siguiente fase
Fase 2, rebanada vertical: el flujo crítico completo de punta a punta. Cada pantalla pasa antes
por su mockup en `docs/plan/mockups/`.

## Cómo verificar el estado actual

```bash
cd ~/Desktop/proyectoslm/lm-tickets
mysql -u root -e "SHOW DATABASES LIKE 'lm_%'"   # deben existir lm_tickets y lm_tickets_testing
php artisan migrate                              # aplica migraciones a lm_tickets
composer check                                   # pint, phpstan y phpunit en verde
php artisan serve                                # http://127.0.0.1:8000 -> portada 200
```
