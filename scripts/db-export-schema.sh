#!/usr/bin/env bash
# scripts/db-export-schema.sh — genera el SQL para crear la base de datos de la aplicación en OTRO servidor (p. ej. IONOS).
#
# Qué es: crea una base TEMPORAL vacía, le aplica las migraciones y el catálogo inicial (productos y categorías) y
# vuelca sus tablas a UN fichero .sql en storage/exports/. NO sale de tu base de desarrollo, así que NO lleva empresas,
# usuarios, tickets, adjuntos, registros ni ningún dato de clientes. Uso:   bash scripts/db-export-schema.sh
#
# Cuándo tocarlo: al añadir tablas que lleven DATOS INICIALES necesarios (hoy solo `products` y `categories`, de
# CatalogSeeder) o al cambiar de motor de base de datos. Si cambia el esquema (migración nueva), basta volver a ejecutarlo.
#
# Por qué así:
#   · Se parte de las migraciones y no de un volcado de la base de desarrollo, para no arrastrar datos de prueba.
#   · Incluye la tabla `migrations` rellena: así, si luego se ejecuta `php artisan migrate` en el servidor, Laravel sabe que
#     ya está todo aplicado y no intenta crear nada dos veces. Y las migraciones futuras se aplican con normalidad.
#   · NO lleva CREATE DATABASE ni USE: en IONOS la base se crea desde su panel (con el nombre y el usuario que ellos asignan)
#     y el fichero se importa DENTRO de ella.
#   · --no-tablespaces y --set-gtid-purged=OFF: sin ellos el volcado exige privilegios que un hosting compartido no da, o trae
#     órdenes de GTID que el servidor rechaza al importar.
#   · La contraseña va por MYSQL_PWD, no por línea de comandos.
#
# Trampas:
#   · La base temporal (lm_tickets_export) se BORRA al terminar. Si el script ya existe una con ese nombre se niega a seguir
#     (podría ser otra cosa): bórrala tú a mano.
#   · Probado solo con MySQL 9.6 (la local). Las tablas salen con la colación utf8mb4_unicode_ci (la de la base temporal), no con
#     utf8mb4_0900_ai_ci, que es la que usan los tests: para correos y nombres se comportan igual, pero NO es lo probado a diario.
#     La columna generada `users.admin_slot` y las restricciones CHECK son sintaxis de MySQL 8: MariaDB NO está probado.
#     Antes de importar en IONOS comprueba qué motor y versión da (docs/plan/produccion.md § 2).

set -euo pipefail
cd "$(dirname "$0")/.."

leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
HOST="$(leer DB_HOST)"; PUERTO="$(leer DB_PORT)"; USUARIO="$(leer DB_USERNAME)"; CLAVE="$(leer DB_PASSWORD)"
TEMPORAL="lm_tickets_export"
mysqlc() { MYSQL_PWD="$CLAVE" mysql -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO" "$@"; }

if [ -n "$(mysqlc -N -e "SHOW DATABASES LIKE '$TEMPORAL'")" ]; then
    echo "ERROR: ya existe la base $TEMPORAL; bórrala a mano si es un resto de una ejecución anterior." >&2; exit 1
fi

# Sea cual sea el resultado, la base temporal desaparece.
trap 'mysqlc -e "DROP DATABASE IF EXISTS \`'"$TEMPORAL"'\`"' EXIT

mysqlc -e "CREATE DATABASE \`$TEMPORAL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# Las variables de entorno mandan sobre .env: solo este proceso usa la base temporal.
DB_DATABASE="$TEMPORAL" APP_ENV=local php artisan migrate --force --no-interaction >/dev/null
DB_DATABASE="$TEMPORAL" APP_ENV=local php artisan db:seed --class=CatalogSeeder --force --no-interaction >/dev/null

mkdir -p storage/exports
FICHERO="storage/exports/lm_tickets_esquema_$(date +%Y%m%d-%H%M%S).sql"
COMUN=(--no-tablespaces --set-gtid-purged=OFF --skip-add-locks --skip-comments -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO")
{
    echo "-- Ling Marco Tickets · esquema y datos iniciales. Generado por scripts/db-export-schema.sh el $(date +%F)."
    echo "-- Importar DENTRO de una base de datos ya creada y vacía. No lleva datos de clientes."
    echo "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;"
    MYSQL_PWD="$CLAVE" mysqldump "${COMUN[@]}" --no-data "$TEMPORAL"
    echo "-- Datos iniciales: tabla de migraciones (para que 'php artisan migrate' no repita nada) y catálogo."
    MYSQL_PWD="$CLAVE" mysqldump "${COMUN[@]}" --no-create-info --complete-insert "$TEMPORAL" migrations products categories
    echo "SET FOREIGN_KEY_CHECKS=1;"
} > "$FICHERO"

[ -s "$FICHERO" ] || { echo "ERROR: el fichero salió vacío" >&2; exit 1; }
echo "Creado: $FICHERO ($(du -h "$FICHERO" | cut -f1))"
