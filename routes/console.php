<?php

/*
 * Tareas programadas. Cuándo tocarlo: al programar o quitar una tarea. Para que corran hace falta UNA entrada de cron en el
 * servidor: `* * * * * cd /ruta/lm-tickets && php artisan schedule:run >> /dev/null 2>&1` (ver docs/plan/produccion.md).
 * Sin esa entrada NADA de lo de aquí se ejecuta: `preflight:check` avisa si no hay copia reciente.
 */

use App\Jobs\CreateBackup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Copia de la base de datos cada noche (decisión de Claude por delegación de la usuaria, 2026-11-11). Se ejecuta en el propio
// proceso del planificador, NO por la cola: una copia nocturna no debe depender de que el trabajador de colas esté vivo.
Schedule::call(fn () => (new CreateBackup)->handle())
    ->name('copia-de-seguridad')->dailyAt('03:00')->withoutOverlapping(60);

// Rotación de copias, tras la copia.
Schedule::command('backup:prune')->dailyAt('03:30')->withoutOverlapping();

// Correo entrante (P1): solo si se activa. Cada minuto, sin solaparse. Necesita el cron de `schedule:run` y que el trabajador de colas esté vivo
// (los avisos y los correos salientes van por la cola). Ver docs/plan/produccion.md.
if (config('inbound.enabled')) {
    Schedule::command('inbound:fetch')->everyMinute()->withoutOverlapping(10);
}

// `retention:run` NO está programado, A PROPÓSITO: es irreversible y el plazo de conservación (2 años) está pendiente de validar
// con la asesoría. Cuando se dé el visto bueno: Schedule::command('retention:run')->dailyAt('04:00')->withoutOverlapping();
