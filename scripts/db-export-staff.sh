#!/usr/bin/env bash
# scripts/db-export-staff.sh — genera el SQL para dar de alta en OTRO servidor (p. ej. IONOS) a las personas de Ling Marco,
# más un fichero con sus ENLACES DE INVITACIÓN. Uso:   bash scripts/db-export-staff.sh [URL_BASE]
#   (URL_BASE por defecto: APP_URL del .env de producción de ejemplo, https://tickets.lingmarco.es)
#
# Qué hace: lee de la base LOCAL (DB_DATABASE de .env) a los empleados ACTIVOS con correo @lingmarco.es (administrador y agentes:
# nombre, correo, rol y equipo) y NO copia nada más: ni contraseñas, ni 2FA, ni cuentas de baja ni de prueba (@…test), ni clientes.
# Cada cuenta nace como una invitación SIN ACEPTAR (R18), igual que si el administrador la hubiera invitado desde la aplicación:
#   · contraseña = hash de 64 caracteres aleatorios que nadie conoce (obliga a usar el enlace);
#   · `activated_at` vacío;
#   · una fila en `password_reset_tokens` con el token CIFRADO (broker «invitations», caduca a los 7 días de generarse).
# El token en claro solo sale en el fichero de enlaces; en el SQL va únicamente su hash.
#
# Por qué así:
#   · Se usa el PROPIO código de la aplicación (Password::broker('invitations')->createToken) sobre una base TEMPORAL, no un
#     INSERT escrito a mano: así el formato del token es el que la aplicación valida.
#   · NO se copian las contraseñas locales: son provisionales y conocidas («password»); en producción no deben existir.
#   · El SQL no lleva IDs de la base local (la de destino debe estar VACÍA de usuarios: los IDs empiezan en 1).
#
# Trampas:
#   · El fichero de enlaces (storage/exports/enlaces-invitacion_*.txt) son LLAVES: quien tenga un enlace crea la contraseña de esa
#     cuenta. Se entrega a cada persona por separado, y se borra después. Está en storage/exports/, ignorado por git.
#   · Si la base de destino ya tiene usuarios, la importación fallará por correo repetido o los IDs chocarán: no la fuerces.
#   · La caducidad corre desde que se GENERA, no desde que se importa: generar el SQL justo antes de importarlo.
#   · Hay que haber importado antes el SQL de esquema (scripts/db-export-schema.sh).

set -euo pipefail
cd "$(dirname "$0")/.."

leer() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
HOST="$(leer DB_HOST)"; PUERTO="$(leer DB_PORT)"; USUARIO="$(leer DB_USERNAME)"; CLAVE="$(leer DB_PASSWORD)"; ORIGEN="$(leer DB_DATABASE)"
URL_BASE="${1:-https://tickets.lingmarco.es}"
TEMPORAL="lm_tickets_staff_export"
mysqlc() { MYSQL_PWD="$CLAVE" mysql -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO" "$@"; }

if [ -n "$(mysqlc -N -e "SHOW DATABASES LIKE '$TEMPORAL'")" ]; then
    echo "ERROR: ya existe la base $TEMPORAL; bórrala a mano si es un resto de una ejecución anterior." >&2; exit 1
fi
SCRIPT_PHP="$(mktemp)"
trap 'mysqlc -e "DROP DATABASE IF EXISTS \`'"$TEMPORAL"'\`"; rm -f "$SCRIPT_PHP"' EXIT

# Personas de origen: activas, de Ling Marco, y NO de prueba. Separadas por tabulador.
PERSONAS="$(mysqlc -N -B "$ORIGEN" -e "SELECT name, email, role, IFNULL(team,'') FROM users WHERE role IN ('admin','agent') AND deactivated_at IS NULL AND email LIKE '%@lingmarco.es' ORDER BY FIELD(role,'admin','agent'), id")"
[ -n "$PERSONAS" ] || { echo "ERROR: no hay empleados que exportar" >&2; exit 1; }

mysqlc -e "CREATE DATABASE \`$TEMPORAL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

FICHERO_ENLACES="storage/exports/enlaces-invitacion_$(date +%Y%m%d-%H%M%S).txt"
mkdir -p storage/exports
umask 077   # el fichero de enlaces solo lo lee quien lo genera

cat > "$SCRIPT_PHP" <<'PHP'
<?php
// Crea las cuentas en la base temporal con el código de la aplicación y escribe los enlaces. Argumentos: URL_BASE FICHERO_ENLACES
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $base, $salida] = $argv;
$out = ["ENLACES DE INVITACIÓN — ".date('Y-m-d H:i')." — caducan a los 7 días de esta fecha. SON LLAVES: uno por persona, por un canal privado; borrar este fichero después.", ''];
foreach (explode("\n", trim(stream_get_contents(STDIN))) as $linea) {
    [$name, $email, $role, $team] = explode("\t", $linea);
    $u = new App\Models\User;
    $u->fill(['name' => $name, 'email' => $email]);
    $u->forceFill([
        'role' => App\Enums\UserRole::from($role),
        'team' => $team === '' ? null : App\Enums\Team::from($team),
        'password' => Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(64)),
        'activated_at' => null,
    ])->save();
    $token = Illuminate\Support\Facades\Password::broker('invitations')->createToken($u);
    $out[] = sprintf("%s <%s> [%s%s]\n  %s/invitacion/%s?email=%s\n", $name, $email, $role, $team ? '/'.$team : '', rtrim($base, '/'), $token, rawurlencode($email));
}
file_put_contents($salida, implode("\n", $out)."\n");
PHP

DB_DATABASE="$TEMPORAL" APP_ENV=local php artisan migrate --force --no-interaction >/dev/null
printf '%s\n' "$PERSONAS" | DB_DATABASE="$TEMPORAL" APP_ENV=local php "$SCRIPT_PHP" "$URL_BASE" "$FICHERO_ENLACES"

FICHERO="storage/exports/lm_tickets_usuarios_ling_marco_$(date +%Y%m%d-%H%M%S).sql"
{
    echo "-- Ling Marco Tickets · cuentas del equipo como INVITACIONES sin aceptar. Generado por scripts/db-export-staff.sh el $(date +%F)."
    echo "-- Importar en una base que YA tiene el esquema (lm_tickets_esquema_*.sql) y NO tiene usuarios. No lleva contraseñas conocidas."
    echo "SET NAMES utf8mb4;"
    MYSQL_PWD="$CLAVE" mysqldump --no-tablespaces --set-gtid-purged=OFF --skip-add-locks --skip-comments --no-create-info \
        --complete-insert --skip-extended-insert -h "$HOST" -P "${PUERTO:-3306}" -u "$USUARIO" "$TEMPORAL" users password_reset_tokens
} > "$FICHERO"

echo "SQL:      $FICHERO"
echo "Enlaces:  $FICHERO_ENLACES   (LLAVES: no compartir ni subir a ningún sitio)"
echo "Cuentas:  $(printf '%s\n' "$PERSONAS" | wc -l | tr -d ' ')"
