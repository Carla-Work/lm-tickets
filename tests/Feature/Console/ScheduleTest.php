<?php

/*
 * Lo que el planificador programa (routes/console.php). Cuándo tocarlo: al programar o quitar una tarea. Vigila dos cosas:
 *  - que la COPIA nocturna y su rotación estén programadas (si alguien las quita, nadie se entera hasta que haga falta una copia);
 *  - que `retention:run` NO esté programado: es IRREVERSIBLE y su plazo está pendiente de validar con la asesoría (docs/plan/conservacion-datos.md).
 *    Programarlo requiere decidirlo y cambiar ESTE test a propósito.
 * Si falla, se arregla routes/console.php o se discute la decisión: NO se adapta el test para que pase.
 */

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /** @return list<Event> */
    private function tareas(): array
    {
        return app(Schedule::class)->events();
    }

    #[TestDox('La copia de seguridad se hace cada noche a las 03:00 y la rotación a las 03:30')]
    public function test_backup_and_prune_are_scheduled(): void
    {
        $copia = collect($this->tareas())->first(fn (Event $e) => $e->description === 'copia-de-seguridad');
        $this->assertNotNull($copia, 'falta la copia de seguridad programada');
        $this->assertSame('0 3 * * *', $copia->expression);

        $rotacion = collect($this->tareas())->first(fn (Event $e) => str_contains((string) $e->command, 'backup:prune'));
        $this->assertNotNull($rotacion, 'falta la rotación de copias programada');
        $this->assertSame('30 3 * * *', $rotacion->expression);
    }

    #[TestDox('retention:run NO está programado (irreversible; plazo por validar con la asesoría)')]
    public function test_retention_is_not_scheduled(): void
    {
        $this->assertFalse(
            collect($this->tareas())->contains(fn (Event $e) => str_contains((string) $e->command, 'retention:run')),
            'retention:run está programado: es irreversible y su plazo no está validado',
        );
    }
}
