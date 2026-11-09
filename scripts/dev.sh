#!/usr/bin/env bash
# scripts/dev.sh — arranca el entorno de desarrollo SIN Node.
#
# Qué es: lo que lanza `composer dev`. Levanta tres procesos y los para todos con Ctrl+C:
#   · servidor web  (php artisan serve)
#   · cola de trabajos  (php artisan queue:listen) — hace falta para enviar los avisos por correo
#   · registro de la aplicación en vivo  (php artisan pail)
#
# Cuándo tocarlo: solo si cambia lo que necesita el entorno (por ejemplo, un proceso nuevo).
#
# Por qué existe: el comando que trae Laravel 13 (`php artisan dev`) ejecuta los procesos con
# `npx @laravel/multiplex`, que DESCARGA un paquete de npm la primera vez. Este proyecto no usa
# Node (decisión de la usuaria) ni se instalan dependencias sin su visto bueno, así que se
# arranca lo mismo directamente con PHP.
#
# Trampa: el puerto lo elige `artisan serve`; si el 8000 está ocupado por otro proyecto, usa el
# siguiente (8001…) y lo imprime. No se fija un puerto a propósito para no pisar otras apps.

set -euo pipefail
cd "$(dirname "$0")/.."

php artisan serve &
php artisan queue:listen --tries=1 --timeout=0 &
php artisan pail --timeout=0 &

# Al salir (Ctrl+C o error) se paran SOLO los procesos que lanzó este script, nunca otros.
trap 'kill $(jobs -p) 2>/dev/null || true' INT TERM EXIT
wait
