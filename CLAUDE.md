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
7. Un agente (o el cliente, explicando cómo) lo pasa a resuelto y un agente lo cierra. Nadie reabre: se crea uno nuevo relacionado.

## Antes de tocar nada, leer

| Documento | Qué resuelve |
|---|---|
| `docs/plan/00-encuadre.md` | Alcance, exclusiones, roles, reglas R1–R21, plan P0/P1. **Manda sobre cualquier suposición.** |
| `docs/plan/conservacion-datos.md` | Plazos de conservación (provisional 2 años; sin cerrar). |
| `docs/plan/bitacoras/` | Qué se hizo, decidió y qué queda; leer el último día. |
| `ONBOARDING.md` | Estado actual y cómo verificarlo. |

## Reglas que no se negocian

Detalle y origen en `docs/plan/00-encuadre.md`. Cada una lleva test; no se «simplifican» al refactorizar.

- **R1** un usuario nunca ve tickets de otra empresa.
- **R2** las notas internas nunca llegan al cliente (ni panel ni email).
- **R3** los adjuntos solo se descargan con autorización; almacenamiento privado fuera de `public/`.
- **R4** un ticket resuelto o cerrado no admite mensajes del cliente ni lo puede reabrir; debe abrir uno nuevo.
- **R5** cada cambio de estado o asignación queda registrado.
- **R6/R7** producto: validado en servidor y solo asignable por el administrador.
- **R8/R9/R10** administrador: único (el jefe; absorbe al antiguo supervisor), no se elimina sin traspasar el rol, ve todos los tickets y puede reasignarlos, pero no responder ni cambiar estado **salvo en «Otros»**, donde gestiona todo.
- **R11** los agentes ven todos los tickets salvo «Otros»; agentes y administrador asignan cualquiera a cualquier agente, también del otro equipo; cada reasignación queda registrada.
- **R12** cualquier agente puede corregir el departamento de un ticket; queda registrado y se avisa al cliente.
- **R13** los tickets de «Otros» solo los ven y responden el administrador y el cliente que los abrió; los agentes no los ven por ninguna vía. Sin suplente en la aplicación: si el administrador falta, los avisa él a los clientes (teléfono o correo).
- **R14** cuando el cliente responde en un ticket «Pendiente de cliente», el estado pasa solo a «En curso».
- **R15** nadie reabre un ticket resuelto o cerrado; desde resuelto solo se pasa a cerrado (lo hace un agente). Misma incidencia = ticket nuevo relacionado.
- **R16** pasar un ticket a resuelto (cliente o agente) exige explicar cómo se resolvió. Solo el cliente puede abrir uno nuevo relacionado; agentes y administrador no clonan.
- **R17** el error de acceso es genérico y la recuperación de contraseña responde igual exista o no el correo; el enlace caduca y es de un solo uso.
- **R18** no hay registro libre: toda cuenta se crea por invitación por correo.
- **R19** un producto asignado a alguna empresa no se borra, solo se desactiva.
- **R20** tras 5 intentos fallidos de acceso se bloquea el acceso un tiempo.
- **R21** el administrador entra con segundo factor (método por decidir; no se implementa hasta tener mockup y decisión).

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
# Arrancar el entorno completo (servidor + cola + logs), sin Node. Verificado 2026-11-09.
# Requiere MySQL en marcha y `php artisan migrate`. Usa el puerto 8000 y, si está ocupado,
# el siguiente (lo imprime). Lo lanza scripts/dev.sh.
composer dev

# Tests
composer test
php artisan test --filter NombreDelTest

# Copia de seguridad de la base de datos y restauración (probada 2026-11-09)
bash scripts/db-backup.sh                                  # crea storage/backups/BASE_FECHA.sql.gz
bash scripts/db-restore.sh FICHERO.sql.gz BASE_DESTINO     # se niega a pisar la base en uso salvo FORZAR=1

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
- **No usar `php artisan dev` ni `npx`** — el comando de Laravel 13 descarga `@laravel/multiplex` de npm (se descubrió al probarlo). Usar `composer dev`, que va por `scripts/dev.sh`.
- **Las copias de la base de datos van en `storage/backups/` (ignorado por git)** — contienen datos de clientes. Nunca al repositorio.
- **Los tests no usan SQLite** — MySQL en `lm_tickets_testing`, para que un test verde valga en producción. No apuntar nunca los tests a `lm_tickets`.
- **Nada con diseño se implementa sin mockup validado** en `docs/plan/mockups/`.
- **Los adjuntos van en `storage/app/private/`, que está en `.gitignore`** — son datos de clientes.
- **Las fechas se guardan en UTC** (`config/app.php`); zona horaria de presentación sin decidir.

## Estructura relevante

```
app/                 código de la aplicación (aún el del esqueleto)
config/app.php       idioma, zona horaria, nombre
public/css/app.css   hoja de estilos base (CSS propio)
scripts/             dev.sh (entorno de desarrollo), db-backup.sh y db-restore.sh (copias)
resources/views/     vistas Blade
docs/plan/           encuadre, conservación de datos, bitácoras (en git)
docs/plan/mockups/   25 mockups HTML validados el 2026-11-09
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
