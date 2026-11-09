#!/usr/bin/env bash
# scripts/db-backup.sh — copia de seguridad de la base de datos de la aplicación.
#
# Qué es: vuelca la base indicada en .env (DB_DATABASE) a un fichero .sql.gz con fecha y hora,
# dentro de storage/backups/. Uso:   bash scripts/db-backup.sh
#
# Cuándo tocarlo: al cambiar de motor de base de datos o al pasar a producción (ahí la copia
# deberá salir de la máquina y cifrarse; ver "Trampas").
#
# Por qué así:
#   · --single-transaction  → copia coherente sin bloquear la aplicación mientras se vuelca.
#   · --routines --triggers → por si en el futuro hay procedimientos o disparadores.
#   · --set-gtid-purged=OFF → sin esto el volcado trae una orden de GTID que MySQL RECHAZA al
#     restaurar en el mismo servidor (error 3546) y la copia no se puede restaurar. Se descubrió
#     al probar la restauración. Si algún día hubiera replicación, habría que revisarlo.
#   · La contraseña va por variable de entorno (MYSQL_PWD), no por línea de comandos, para que no
#     aparezca en la lista de procesos.
#
# Trampas:
#   · Una copia que no se ha restaurado no es una copia. Probar con scripts/db-restore.sh.
#   · storage/backups/ está en .gitignore: contendrá DATOS DE CLIENTES. Nunca al repositorio.
#   · En producción la copia debe guardarse FUERA del servidor y cifrada, y su conservación
#     entra en el plan de plazos de datos (docs/plan/conservacion-datos.md): una copia vieja
#     conserva datos que ya se habrían borrado.

set -euo pipefail
cd "$(dirname "$0")/.."

# Lee una variable de .env sin ejecutar el fichero (puede llevar comillas y caracteres raros).
leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB="$(leer DB_DATABASE)"; HOST="$(leer DB_HOST)"; PUERTO="$(leer DB_PORT)"
USUARIO="$(leer DB_USERNAME)"; CLAVE="$(leer DB_PASSWORD)"

DESTINO="storage/backups"
mkdir -p "$DESTINO"
FICHERO="$DESTINO/${DB}_$(date +%Y%m%d-%H%M%S).sql.gz"

MYSQL_PWD="$CLAVE" mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF \
    -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO" "$DB" | gzip > "$FICHERO"

# Comprobación mínima: el fichero comprimido es legible y no está vacío.
gzip -t "$FICHERO"
[ -s "$FICHERO" ] || { echo "ERROR: la copia salió vacía" >&2; exit 1; }

echo "Copia creada: $FICHERO ($(du -h "$FICHERO" | cut -f1))"
