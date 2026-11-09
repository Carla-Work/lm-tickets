#!/usr/bin/env bash
# scripts/db-restore.sh — restaura una copia de seguridad en una base de datos.
#
# Qué es: carga un fichero .sql.gz creado por scripts/db-backup.sh en la base que se indique.
# Uso:   bash scripts/db-restore.sh storage/backups/FICHERO.sql.gz NOMBRE_BASE_DESTINO
#
# Cuándo tocarlo: junto con db-backup.sh.
#
# Por qué existe: "copia de seguridad configurada y RESTAURADA una vez" es requisito de la
# Fase 1. Esto es la mitad que lo demuestra.
#
# Trampa importante: por seguridad se NIEGA a restaurar sobre la base que usa la aplicación
# (DB_DATABASE del .env) para no machacar datos vivos por un error al teclear. Para una
# restauración real sobre esa base hay que decirlo a propósito:  FORZAR=1 bash scripts/db-restore.sh …
# Antes de hacerlo, haz una copia nueva de lo que hay.

set -euo pipefail
cd "$(dirname "$0")/.."

[ $# -eq 2 ] || { echo "Uso: bash scripts/db-restore.sh FICHERO.sql.gz BASE_DESTINO" >&2; exit 2; }
FICHERO="$1"; DESTINO="$2"
[ -f "$FICHERO" ] || { echo "ERROR: no existe $FICHERO" >&2; exit 2; }

leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
HOST="$(leer DB_HOST)"; PUERTO="$(leer DB_PORT)"; USUARIO="$(leer DB_USERNAME)"
CLAVE="$(leer DB_PASSWORD)"; EN_USO="$(leer DB_DATABASE)"

if [ "$DESTINO" = "$EN_USO" ] && [ "${FORZAR:-0}" != "1" ]; then
    echo "ERROR: '$DESTINO' es la base en uso por la aplicación. Restaurar encima BORRA sus datos actuales." >&2
    echo "Si es lo que quieres: FORZAR=1 bash scripts/db-restore.sh $FICHERO $DESTINO" >&2
    exit 3
fi

mysql_() { MYSQL_PWD="$CLAVE" mysql -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO" "$@"; }

# Se crea la base si no existe, con el mismo juego de caracteres que el resto del proyecto.
mysql_ -e "CREATE DATABASE IF NOT EXISTS \`$DESTINO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$FICHERO" | mysql_ "$DESTINO"
echo "Restaurado $FICHERO en la base '$DESTINO'."
