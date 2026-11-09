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

### Sin verificar
- `composer dev` completo (servidor + colas + logs). Se intentó probarlo y el sistema bloqueó el comando; falta ejecutarlo a mano.

### Pendiente en la Fase 1
1. **Copia de seguridad configurada y restaurada una vez** (regla de Fase 1; sin hacer).
2. Verificar `composer dev` a mano.
3. Zona horaria de presentación (hoy UTC).
4. Retirar la copia duplicada de `docs/` en `/Volumes/Proyectos Lingmarco/Publico/lingmarco/` (decide la usuaria).
5. Subida a GitHub (la ejecuta la usuaria).
6. Decidir qué hacer con `docs/marca lingmarco/` (apareció sin commitear; no es de Claude).

### Decisiones abiertas del encuadre
Categorías (propuesta hecha), servicios de soporte del catálogo de productos, plazos definitivos de conservación de datos.

### Mockups
- `docs/plan/mockups/nuevo-ticket-a-departamentos-tarjetas.html`: pantalla «Nuevo ticket». **Validado 2026-11-09.** Departamentos (Soporte, Aplicaciones web, Páginas web, Otros) como campo del ticket, categoría dependiente del departamento, producto de la empresa.
- `docs/plan/mockups/mis-tickets-a-lista-tabla.html`: lista de tickets del usuario. **Sin validar.**
- `docs/plan/mockups/bandeja-a-lista-tabla.html`: bandeja del panel interno, con columna «Abierto por» (persona + empresa) y «Asignado a». **Sin validar.**
- `docs/plan/mockups/ticket-detalle-cliente-a-hilo.html`: detalle del ticket del cliente (estado «Pendiente de tu respuesta»). **Sin validar.**
- `docs/plan/mockups/ticket-detalle-interno-a-hilo.html`: detalle interno (agente que lleva el ticket). **Sin validar.**
- `docs/plan/mockups/ticket-detalle-cliente-b-resuelto.html`: ticket resuelto, sin reabrir, con «Abrir un ticket nuevo» (R4). **Sin validar.**
- `docs/plan/mockups/nuevo-ticket-b-relacionado.html`: nuevo ticket relacionado con uno resuelto. **Sin validar.**
- `docs/plan/mockups/ticket-detalle-interno-c-resuelto.html`: ticket resuelto no cerrado, vista interna. **Sin validar.**
- `docs/plan/mockups/ticket-detalle-interno-b-ajeno.html`: agente que no lleva el ticket, con «Asignármelo». **Sin validar.**
- Acceso: `acceso-a-entrar`, `acceso-b-entrar-error`, `acceso-c-recuperar`, `acceso-d-recuperar-enviado`, `acceso-e-nueva-contrasena`. **Sin validar.**
- Administrador: `admin-empresas-a-lista`, `admin-empresa-b-detalle` (asigna productos), `admin-empresa-c-nueva`, `admin-usuarios-a-empleados` (con traspaso del rol), `admin-catalogo-a-productos-categorias`. **Sin validar.**
- Cliente: `cliente-mi-empresa-a-administrador`, `mis-tickets-b-administrador-empresa`, `mis-datos-a-cliente`, `avisos-a-cliente`. **Sin validar.**
- Interno: `mis-datos-b-interno`, `avisos-b-interno`. **Sin validar.**
- **Ya hay mockup de todas las pantallas previstas.** Falta que la usuaria los valide; las dudas pendientes están en el encuadre, sección «Acceso, administración y panel del cliente».
- Sin dibujar, por no haberse pedido: ticket recién creado sin respuestas, enlace caducado, estados vacíos de las listas, vista del usuario normal en «Mi empresa».
- Decidido: el cierre lo hace un agente; nadie reabre (se clona/crea uno relacionado); el cliente marca «resuelto» directo pero explicando cómo; al responder el cliente el estado pasa solo a «En curso».
- Decidido: solo el cliente clona; el agente también explica cómo resolvió (R16); en «Resuelto» el agente puede escribir mensajes y notas.
- Abierto: si en «Cerrado» nadie escribe (supuesto); si reasignar y cambiar departamento siguen activos en «Resuelto».
- Decidido: el agente ve todos los tickets; hay dos equipos internos (Soporte y Aplicaciones) y cualquier agente puede asignar a alguien del otro equipo (R11).
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
