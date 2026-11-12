<?php

/*
 * Tarea para el CRON de un hosting SIN SSH (IONOS): se ejecuta con `php8.3 …/scripts/cron.php` cada minuto (o el intervalo
 * mínimo que permita el hosting). Cuándo tocarlo: casi nunca; si cambia lo que hay que hacer cada minuto (docs/plan/produccion.md §4-5).
 *
 * Qué hace, en este orden:
 *  1. `schedule:run`: lanza lo programado (copia de la base a las 03:00, rotación a las 03:30; routes/console.php).
 *  2. `queue:work --stop-when-empty`: envía los correos y avisos pendientes y termina. Sin esto no sale ningún correo.
 *
 * Trampas:
 *  - Solo por consola: si alguien lo pidiera por la web (no está en public/, pero por si se mueve) responde 404 y no hace nada.
 *  - `--max-time=45`: el trabajador se detiene solo antes del siguiente minuto, para que no se acumulen procesos si el cron
 *    es cada minuto. Si dos coinciden no pasa nada grave: la cola reserva cada trabajo (no se envía dos veces).
 *  - No escribe nada por pantalla; los fallos quedan en storage/logs/laravel.log.
 */

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

if (isset($_SERVER['REQUEST_METHOD'])) { // petición web, no consola
    http_response_code(404);
    exit;
}

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
chdir(dirname(__DIR__)); // las rutas relativas (p. ej. copias) parten de la raíz de la aplicación

$status = 0;
foreach ([
    ['command' => 'schedule:run'],
    ['command' => 'queue:work', '--stop-when-empty' => true, '--max-time' => 45, '--tries' => 3],
] as $input) {
    try {
        $status |= $app->make(Illuminate\Contracts\Console\Kernel::class)->call($input['command'], array_slice($input, 1), new NullOutput);
    } catch (Throwable $e) {
        report($e);
        $status = 1;
    }
}

exit($status);
