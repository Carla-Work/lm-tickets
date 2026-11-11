<?php

/*
 * La base de datos y la aplicación hablan la MISMA hora (UTC). Cuándo tocarlo: nunca sin tocar `timezone` en la conexión
 * `mysql` de config/database.php. Descubierto el 2026-11-11 (defecto D-13) al cargar datos de prueba: el MySQL de la máquina
 * trabajaba en hora de Canarias (WET, con cambio de hora) y Laravel guarda las fechas en UTC; sin fijar la zona de la sesión,
 * una fecha dentro de la hora que NO existe en ese huso (el último domingo de marzo, 01:00-02:00 UTC) fallaba al guardar, y la
 * hora repetida de octubre podía leerse desplazada.
 * Si falla, se arregla la configuración de la conexión: NO se adapta el test.
 */

namespace Tests\Feature\Schema;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    #[TestDox('La sesión de MySQL trabaja en UTC, igual que la aplicación')]
    public function test_session_is_utc(): void
    {
        $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as zona')->zona);
        $this->assertSame('UTC', config('app.timezone'));
    }

    #[TestDox('Una fecha en la hora que no existe en el huso del servidor (domingo de cambio de marzo, 01:30 UTC) se guarda y se lee igual')]
    public function test_gap_hour_is_stored(): void
    {
        $ticket = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'created_at' => '2026-03-29 01:30:00']);

        $this->assertSame('2026-03-29 01:30:00', $ticket->fresh()->created_at?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Las dos horas repetidas del cambio de octubre siguen siendo dos instantes distintos y se leen sin desplazarse')]
    public function test_repeated_hour_is_not_shifted(): void
    {
        $a = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'created_at' => '2026-10-25 00:30:00']);
        $b = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'created_at' => '2026-10-25 01:30:00']);

        $this->assertSame('2026-10-25 00:30:00', $a->fresh()->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 01:30:00', $b->fresh()->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame(60, (int) DB::selectOne('select TIMESTAMPDIFF(MINUTE, a.created_at, b.created_at) as m from tickets a, tickets b where a.id = ? and b.id = ?', [$a->id, $b->id])->m);
    }
}
