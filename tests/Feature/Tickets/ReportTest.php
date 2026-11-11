<?php

/*
 * Informes del equipo (P1; App\Support\TicketReport y ReportController). Cuándo tocarlo: al cambiar una cifra o su definición.
 * Lo que de verdad vigila:
 *  - las CIFRAS: un conjunto de datos controlado con los resultados calculados a mano (si una definición cambia, este test lo dice);
 *  - la VISIBILIDAD: cada cifra sale solo de lo que esa persona ve (un agente de Soporte no cuenta Contabilidad, R13), el cliente no
 *    entra, y la tabla «por agente» es solo del administrador.
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\TicketReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->crearActores();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    /** Crea un ticket con fechas fijas. $agente = quien lo lleva (clave de actor) o null. */
    private function t(string $creado, ?string $resuelto, TicketStatus $estado, ?string $agente, Department $dep = Department::Support, string $prioridad = 'normal', ?int $valoracion = null, ?string $valorado = null): Ticket
    {
        $ticket = Ticket::factory()->create([
            'company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => $dep->value, 'status' => $estado->value,
            'assigned_to' => $agente === null ? null : $this->a[$agente]->id, 'priority' => $prioridad,
            'created_at' => $creado, 'resolved_at' => $resuelto,
        ]);
        if ($valoracion !== null) {
            DB::table('ticket_ratings')->insert(['ticket_id' => $ticket->id, 'rated_by' => $this->a['cuA']->id, 'score' => $valoracion, 'created_at' => $valorado]);
        }

        return $ticket;
    }

    private function mensaje(Ticket $t, string $autor, string $cuando, MessageKind $tipo = MessageKind::Public): void
    {
        $factory = TicketMessage::factory();
        ($tipo === MessageKind::Internal ? $factory->internal() : $factory)->create(['ticket_id' => $t->id, 'author_id' => $this->a[$autor]->id, 'body' => 'x', 'created_at' => $cuando]);
    }

    /** El conjunto de datos del test (noviembre de 2026; «hoy» es el 20). Ver las cifras esperadas en cada test. */
    private function datos(): void
    {
        $t1 = $this->t('2026-11-02 10:00', '2026-11-02 14:00', TicketStatus::Resolved, 'agSup', valoracion: 5, valorado: '2026-11-03 09:00');
        $this->mensaje($t1, 'agSup', '2026-11-02 10:30');

        $t2 = $this->t('2026-11-05 09:00', '2026-11-06 09:00', TicketStatus::Closed, 'agSup', valoracion: 3, valorado: '2026-11-07 09:00');
        $this->mensaje($t2, 'cuA', '2026-11-05 09:05'); // el cliente escribe primero: NO cuenta como respuesta del equipo
        $this->mensaje($t2, 'agSup', '2026-11-05 11:00');

        $t3 = $this->t('2026-11-10 08:00', null, TicketStatus::InProgress, 'agApp', prioridad: 'urgent');
        $this->mensaje($t3, 'agSup', '2026-11-10 08:05', MessageKind::Internal); // una nota NO cuenta como respuesta
        $this->mensaje($t3, 'agApp', '2026-11-10 09:00');

        $this->t('2026-11-15 10:00', null, TicketStatus::Open, null, prioridad: 'high'); // sin respuesta y sin asignar

        $t5 = $this->t('2026-11-12 10:00', '2026-11-12 12:00', TicketStatus::Resolved, 'agApp', Department::WebApps, valoracion: 1, valorado: '2026-11-13 09:00');
        $this->mensaje($t5, 'agApp', '2026-11-12 10:10');

        $t6 = $this->t('2026-11-08 10:00', '2026-11-09 10:00', TicketStatus::Closed, 'agCon', Department::Other, valoracion: 2, valorado: '2026-11-10 09:00');
        $this->mensaje($t6, 'agCon', '2026-11-08 10:20');

        // Fuera del periodo: creado y resuelto en septiembre (con su valoración de septiembre).
        $this->t('2026-09-01 10:00', '2026-09-05 10:00', TicketStatus::Closed, 'agSup', valoracion: 4, valorado: '2026-09-06 09:00');
        // Creado en octubre pero RESUELTO en noviembre: cuenta como resuelto, no como creado.
        $this->t('2026-10-28 08:00', '2026-11-03 08:00', TicketStatus::Resolved, 'agSup');
    }

    private function informe(string $quien): TicketReport
    {
        return new TicketReport($this->a[$quien], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
    }

    #[TestDox('El administrador ve las cifras de todo: creados, resueltos, vivos, primera respuesta (sin contar notas internas), tiempo hasta resolver y valoración media del periodo')]
    public function test_totals_for_admin(): void
    {
        $this->datos();
        $totals = $this->informe('admin')->totals();

        $this->assertSame(6, $totals['created'], 'creados en noviembre: T1,T2,T3,T4,T5,T6');
        $this->assertSame(5, $totals['resolved'], 'resueltos en noviembre: T1,T2,T5,T6 y el de octubre');
        $this->assertSame(2, $totals['live'], 'vivos hoy: T3 y T4');
        $this->assertSame(1, $totals['unassigned'], 'vivo sin asignar: T4');
        $this->assertEqualsWithDelta(48.0, $totals['first_response_min'], 0.01, '(30+120+60+10+20)/5; la nota interna de T3 no cuenta, T4 no tiene respuesta');
        $this->assertEqualsWithDelta(39.6, $totals['resolution_hours'], 0.01, '(240+1440+120+1440+8640) min / 5 = 2376 min = 39,6 h');
        $this->assertEqualsWithDelta(2.75, $totals['rating_avg'], 0.001, '(5+3+1+2)/4; la valoración de septiembre queda fuera');
        $this->assertSame(4, $totals['rating_count']);
    }

    #[TestDox('Por departamento: solo los que esa persona atiende, con sus cifras')]
    public function test_by_department(): void
    {
        $this->datos();

        $filas = collect($this->informe('admin')->byDepartment())->keyBy(fn ($r) => $r['department']->value);
        $this->assertSame(['support', 'web_apps', 'websites', 'other'], $filas->keys()->all());
        $this->assertSame([4, 3, 2], [$filas['support']['created'], $filas['support']['resolved'], $filas['support']['live']]);
        $this->assertEqualsWithDelta(57.3, $filas['support']['resolution_hours'], 0.05, '(240+1440+8640)/3 = 3440 min');
        $this->assertEqualsWithDelta(4.0, $filas['support']['rating_avg'], 0.001);
        $this->assertSame([1, 1, 0], [$filas['web_apps']['created'], $filas['web_apps']['resolved'], $filas['web_apps']['live']]);
        $this->assertEqualsWithDelta(1.0, $filas['web_apps']['rating_avg'], 0.001);
        $this->assertSame([0, 0, 0], [$filas['websites']['created'], $filas['websites']['resolved'], $filas['websites']['live']]);
        $this->assertNull($filas['websites']['resolution_hours'], 'sin resueltos no hay media (ni división por cero)');
        $this->assertNull($filas['websites']['rating_avg']);
        $this->assertEqualsWithDelta(24.0, $filas['other']['resolution_hours'], 0.01);
    }

    #[TestDox('R13: un agente de Soporte no cuenta ni ve Contabilidad en NINGUNA cifra; el de Contabilidad solo la suya')]
    public function test_contabilidad_is_isolated(): void
    {
        $this->datos();

        $soporte = $this->informe('agSup');
        $totals = $soporte->totals();
        $this->assertSame(5, $totals['created'], 'sin el de Contabilidad');
        $this->assertSame(4, $totals['resolved']);
        $this->assertEqualsWithDelta(55.0, $totals['first_response_min'], 0.01, '(30+120+60+10)/4');
        $this->assertEqualsWithDelta(43.5, $totals['resolution_hours'], 0.01, '(240+1440+120+8640)/4 = 2610 min');
        $this->assertEqualsWithDelta(3.0, $totals['rating_avg'], 0.001, '(5+3+1)/3');
        $this->assertSame(['support', 'web_apps', 'websites'], array_map(fn ($r) => $r['department']->value, $soporte->byDepartment()));

        $conta = $this->informe('agCon');
        $this->assertSame(1, $conta->totals()['created']);
        $this->assertSame(['other'], array_map(fn ($r) => $r['department']->value, $conta->byDepartment()));
        $this->assertEqualsWithDelta(20.0, $conta->totals()['first_response_min'], 0.01);
        $this->assertEqualsWithDelta(2.0, $conta->totals()['rating_avg'], 0.001);
    }

    #[TestDox('Por agente: solo el administrador (los agentes no ven la tabla ni los nombres de sus compañeros); la atribución es a quien lo lleva ahora')]
    public function test_by_agent_is_admin_only(): void
    {
        $this->datos();

        $this->assertSame([], $this->informe('agSup')->byAgent());
        $this->assertSame([], $this->informe('agCon')->byAgent());

        $filas = collect($this->informe('admin')->byAgent())->keyBy('name');
        $sup = $filas[$this->a['agSup']->name];
        $this->assertSame([3, 0], [$sup['resolved'], $sup['live']], 'T1, T2 y el de octubre');
        $this->assertEqualsWithDelta(57.3, $sup['resolution_hours'], 0.05);
        $this->assertEqualsWithDelta(4.0, $sup['rating_avg'], 0.001);
        $this->assertSame(2, $sup['rating_count']);
        $app = $filas[$this->a['agApp']->name];
        $this->assertSame([1, 1], [$app['resolved'], $app['live']], 'resolvió T5 y lleva T3 vivo');
        $this->assertEqualsWithDelta(1.0, $app['rating_avg'], 0.001);
        $con = $filas[$this->a['agCon']->name];
        $this->assertSame(1, $con['resolved']);
    }

    #[TestDox('Vivos por prioridad (interna): cuenta solo los tickets vivos que esa persona ve')]
    public function test_live_priorities(): void
    {
        $this->datos();

        $this->assertSame(['low' => 0, 'normal' => 0, 'high' => 1, 'urgent' => 1], $this->informe('admin')->livePriorities());
        $this->t('2026-11-18 10:00', null, TicketStatus::Open, null, Department::Other, 'urgent');
        $this->assertSame(1, $this->informe('agSup')->livePriorities()['urgent'], 'el urgente de Contabilidad no lo cuenta Soporte');
        $this->assertSame(2, $this->informe('admin')->livePriorities()['urgent']);
    }

    #[TestDox('El periodo incluye TODO el primer y el último día: un ticket del 30 a las 15:00 y otro del 1 a las 00:30 cuentan; el del 1 de diciembre a las 00:10 no')]
    public function test_period_boundaries(): void
    {
        $this->t('2026-11-30 15:00', null, TicketStatus::Open, null);
        $this->t('2026-11-01 00:30', null, TicketStatus::Open, null);
        $this->t('2026-12-01 00:10', null, TicketStatus::Open, null);
        $this->t('2026-10-31 23:50', null, TicketStatus::Open, null);

        $this->assertSame(2, $this->informe('admin')->totals()['created']);
    }

    #[TestDox('Un periodo de más de 3 años se recorta de verdad al máximo permitido (no solo se avisa)')]
    public function test_long_period_is_trimmed(): void
    {
        $recortado = Carbon::parse('2026-11-01')->subDays(TicketReport::MAX_DAYS)->translatedFormat('j M Y');

        $this->como('admin')->get(route('reports.index', ['desde' => '2010-01-01', 'hasta' => '2026-11-01']))->assertOk()
            ->assertSee('se ha recortado')->assertSee('Del '.$recortado);
    }

    #[TestDox('Sin datos no hay error ni división por cero: cifras a cero y medias nulas')]
    public function test_empty_report(): void
    {
        $totals = $this->informe('admin')->totals();

        $this->assertSame([0, 0, 0, 0], [$totals['created'], $totals['resolved'], $totals['live'], $totals['unassigned']]);
        $this->assertNull($totals['first_response_min']);
        $this->assertNull($totals['resolution_hours']);
        $this->assertNull($totals['rating_avg']);
        $this->assertSame([], $this->informe('admin')->byCompany());

        $this->como('admin')->get(route('reports.index'))->assertOk()->assertSee('Informes')->assertSee('No se ha creado ningún ticket en este periodo.');
    }

    #[TestDox('La pantalla enseña las cifras con su formato (tiempos y estrellas) y las definiciones; la tabla por agente solo para el administrador')]
    public function test_screen(): void
    {
        $this->datos();
        $url = route('reports.index', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);

        $this->como('admin')->get($url)->assertOk()
            ->assertSee('48 min')->assertSee('39 h 36 min')->assertSee('★ 2,8 / 5', false)->assertSee('Cómo se calcula')
            ->assertSee('Por agente (solo lo ve el administrador)')->assertSee($this->a['agSup']->name);

        $this->como('agSup')->get($url)->assertOk()->assertSee('55 min')->assertDontSee('Por agente (solo lo ve el administrador)')->assertDontSee($this->a['agApp']->name);
    }

    #[TestDox('R13 en la pantalla: la empresa que solo tiene tickets de Contabilidad no aparece (ni su nombre) en el informe de un agente de Soporte')]
    public function test_company_names_respect_scope(): void
    {
        $this->t('2026-11-05 10:00', null, TicketStatus::Open, null);
        Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'department' => 'other', 'created_at' => '2026-11-06 10:00']);
        $url = route('reports.index', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);

        $this->como('agSup')->get($url)->assertOk()->assertSee($this->empresaA->name)->assertDontSee($this->empresaB->name);
        $this->como('admin')->get($url)->assertOk()->assertSee($this->empresaA->name)->assertSee($this->empresaB->name);
        $this->como('agCon')->get($url)->assertOk()->assertSee($this->empresaB->name)->assertDontSee($this->empresaA->name);
    }

    #[TestDox('Solo el equipo entra: un cliente (usuario o administrador de empresa) recibe 403 y sin sesión se pide acceso; el menú tiene «Informes» solo para el equipo')]
    public function test_access(): void
    {
        foreach (['cuA', 'caA', 'cuB'] as $cliente) {
            $this->como($cliente)->get(route('reports.index'))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get(route('reports.index'))->assertRedirect(route('login'));

        $this->como('agSup')->get(route('inbox'))->assertOk()->assertSee(route('reports.index'), false);
        $this->como('cuA')->get(route('tickets.index'))->assertOk()->assertDontSee(route('reports.index'), false);
    }

    #[TestDox('El periodo se valida: fechas cruzadas o mal escritas se rechazan con mensaje; uno de más de 3 años se recorta con aviso')]
    public function test_period_validation(): void
    {
        $this->como('admin')->get(route('reports.index', ['desde' => '2026-05-10', 'hasta' => '2026-05-01']))->assertSessionHasErrors('hasta');
        $this->como('admin')->get(route('reports.index', ['desde' => 'ayer']))->assertSessionHasErrors('desde');

        $this->como('admin')->get(route('reports.index', ['desde' => '2010-01-01', 'hasta' => '2026-11-01']))->assertOk()->assertSee('se ha recortado');
        $this->como('admin')->get(route('reports.index'))->assertOk()->assertSee('Del 22 oct. 2026 al 20 nov. 2026');
    }

    #[TestDox('Las cifras no hacen consultas por cada ticket: con muchos más tickets, el número de consultas de la pantalla es el mismo')]
    public function test_no_n_plus_one(): void
    {
        $this->datos();
        $url = route('reports.index', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
        $contar = function () use ($url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->como('admin')->get($url)->assertOk();

            return count(DB::getQueryLog());
        };

        $antes = $contar();
        for ($i = 0; $i < 15; $i++) {
            $t = $this->t('2026-11-1'.($i % 9).' 10:00', '2026-11-1'.($i % 9).' 12:00', TicketStatus::Resolved, 'agSup');
            $this->mensaje($t, 'agSup', '2026-11-1'.($i % 9).' 10:30');
        }
        $this->assertSame($antes, $contar());
    }
}
