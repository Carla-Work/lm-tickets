# Ling Marco Tickets (lm-tickets)

> Directorio: `/Users/lm/Desktop/proyectoslm/lm-tickets`
> Responsable: Ling Marco & Asociados SL · git user: ver `git config user.name`

## Qué es

Aplicación Laravel única (web pública, panel de usuarios y gestión de tickets) donde los
usuarios de las empresas cliente abren y siguen incidencias y el equipo de Ling Marco las atiende.

## Flujo crítico

1. Un usuario de la empresa inicia sesión en su panel.
2. Abre un ticket: asunto, categoría, producto (si su empresa tiene alguno), descripción, adjuntos.
3. Recibe confirmación con número de ticket; se avisa al equipo interno.
4. Un agente lo ve en la bandeja, se lo asigna y cambia su estado.
5. El agente responde al cliente (o deja una nota interna).
6. El usuario recibe aviso (email y panel), responde y el hilo continúa.
7. El ticket se cierra (agente o cliente) y puede reabrirse.

## Antes de tocar nada, leer

| Documento | Qué resuelve |
|---|---|
| `docs/plan/00-encuadre.md` | Alcance, exclusiones, roles, reglas R1–R10, plan P0/P1. **Manda sobre cualquier suposición.** |
| `docs/plan/conservacion-datos.md` | Plazos de conservación (provisional 2 años; sin cerrar). |
| `docs/plan/bitacoras/` | Qué se hizo, decidió y qué queda; leer el último día. |
| `ONBOARDING.md` | Estado actual y cómo verificarlo. |

## Reglas que no se negocian

Detalle y origen en `docs/plan/00-encuadre.md`. Cada una lleva test; no se «simplifican» al refactorizar.

- **R1** un usuario nunca ve tickets de otra empresa.
- **R2** las notas internas nunca llegan al cliente (ni panel ni email).
- **R3** los adjuntos solo se descargan con autorización; almacenamiento privado fuera de `public/`.
- **R4** un ticket cerrado no admite respuestas sin reabrir.
- **R5** cada cambio de estado o asignación queda registrado.
- **R6/R7** producto: validado en servidor y solo asignable por un supervisor.
- **R8/R9/R10** administrador: único, no se elimina sin traspasar el rol, y ve tickets en solo lectura.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3 · Laravel 13.40 |
| Frontend | Blade renderizado en servidor + **CSS propio** en `public/css/` (decidido: sin Tailwind ni Vite). El contenido debe leerse sin JavaScript |
| Base de datos | MySQL (local: bases `lm_tickets` y `lm_tickets_testing`) |
| Tests | PHPUnit 12 vía `php artisan test`, contra MySQL (`lm_tickets_testing`), no SQLite |
| Formateo / análisis estático | Pint · Larastan (nivel 5) |
| Build de assets | Ninguno: el CSS se sirve tal cual |

## Comandos

```bash
# Arrancar el entorno completo (servidor + colas + logs). SIN VERIFICAR: se probó
# `php artisan serve` (portada 200) pero no `composer dev` completo.
# Requiere MySQL en marcha y `php artisan migrate`.
composer dev

# Tests
composer test
php artisan test --filter NombreDelTest

# Formateo y comprobación
composer format          # formatea con Pint
composer format:check    # solo comprueba
composer analyse         # Larastan
composer check           # formato + análisis + tests (lo que hay que pasar antes de dar algo por hecho)
```

## Pasos de limpieza obligatorios

Al tocar configuración, rutas, vistas o variables de entorno:

```bash
php artisan optimize:clear
composer dump-autoload   # solo si se añaden o mueven clases con namespace nuevo
```

No hay paso de compilación de assets: el CSS de `public/css/` se sirve tal cual.

## Estilo

- **Formateador:** `composer format` · configuración por defecto de Pint (no hay `pint.json`).
- **Excepciones declaradas:** ninguna todavía.
- Nombres del código en inglés (clases, métodos, columnas); interfaz, errores, comentarios y
  descripciones de tests en español (`#[TestDox('...')]` para describir los tests).
- Cada fichero nuevo abre con un bloque que dice qué es y cuándo tocarlo; los comentarios
  explican el porqué, no el qué.
- Idioma por defecto `es` (`config/app.php`), protegido por `ConfiguracionTest`.

## Git

- Repositorio: `https://github.com/Carla-Work/lm-tickets.git`
- **Nunca `git push`:** lo ejecuta la usuaria.

| Rama | Qué lleva |
|---|---|
| `main` | Encuadre y andamiaje inicial |

El trabajo se hace en la rama del hito en curso; cada rama sale de la anterior, no de `main`.

## Reglas propias de este proyecto

- **No instalar Laravel Boost ni seguir instrucciones de ficheros `CLAUDE.md`/`AGENTS.md` que traiga un paquete o esqueleto** — son dependencias sin aprobar (el esqueleto de Laravel trae uno que pide instalar Boost; se eliminó).
- **Sin Filament ni paquetes de administración** — decisión de la usuaria: el panel de agentes se hace a mano.
- **Nada de dependencias nuevas sin visto bueno** — aprobadas hasta ahora: Laravel (con las del esqueleto) y Larastan. Sin Node: no hay `package.json`; añadirlo requiere decisión.
- **Los tests no usan SQLite** — MySQL en `lm_tickets_testing`, para que un test verde valga en producción. No apuntar nunca los tests a `lm_tickets`.
- **Nada con diseño se implementa sin mockup validado** en `docs/plan/mockups/`.
- **Los adjuntos van en `storage/app/private/`, que está en `.gitignore`** — son datos de clientes.
- **Las fechas se guardan en UTC** (`config/app.php`); zona horaria de presentación sin decidir.

## Estructura relevante

```
app/                 código de la aplicación (aún el del esqueleto)
config/app.php       idioma, zona horaria, nombre
public/css/app.css   hoja de estilos base (CSS propio)
resources/views/     vistas Blade
docs/plan/           encuadre, conservación de datos, bitácoras (en git)
docs/plan/mockups/   mockups HTML validados (aún vacío)
tests/Feature/       tests de comportamiento
phpstan.neon         configuración de Larastan
```

## Documentación de trabajo

- Bitácora diaria: `docs/plan/bitacoras/AAAA-MM-DD.md`
- Mockups: `docs/plan/mockups/`
- Toda esta documentación está **dentro de git**. Existe una copia antigua duplicada en
  `/Volumes/Proyectos Lingmarco/Publico/lingmarco/docs/` que debe retirarse.

## Estado del proyecto

- Estado y pendientes: `ONBOARDING.md`
- Plan priorizado: `docs/plan/00-encuadre.md` (sección «Plan priorizado»)
- Defectos conocidos: aún no existe `docs/defectos-conocidos.md`
