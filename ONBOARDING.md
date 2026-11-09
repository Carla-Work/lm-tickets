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
