<?php

/*
 * Búsqueda y filtros avanzados de la bandeja y de «Mis tickets» (P1; App\Support\TicketFilters).
 * Criterio de aceptación en docs/plan/p1-busqueda-y-filtros.md. Cuándo tocarlo: al cambiar un filtro.
 * Lo que de verdad vigila: que ningún filtro AMPLÍE lo que cada persona ve (R1, R13) y que la búsqueda en mensajes no
 * delate una nota interna a un cliente (R2). Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\TicketFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TicketFiltersTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** Ticket de la empresa A abierto por cuA, con asunto propio. */
    private function conAsunto(string $asunto, Department $dep = Department::Support, TicketStatus $estado = TicketStatus::Open, array $extra = []): Ticket
    {
        return Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'subject' => $asunto, 'department' => $dep->value, 'status' => $estado->value] + $extra);
    }

    private function mensaje(Ticket $t, string $texto, bool $interno = false): void
    {
        $factory = TicketMessage::factory();
        ($interno ? $factory->internal() : $factory)->create(['ticket_id' => $t->id, 'author_id' => $this->a['agSup']->id, 'body' => $texto]);
    }

    /** @return list<string> asuntos que ve la lista de esa persona con esos filtros (HTTP real, así pasa por la validación) */
    private function asuntos(string $quien, array $filtros, bool $equipo = false): array
    {
        $this->app['auth']->forgetGuards();
        $html = $this->actingAs($this->a[$quien])->get(route($equipo ? 'inbox' : 'tickets.index', $filtros))->assertOk()->getContent();
        preg_match_all('#class="asunto" href="[^"]+">([^<]+)</a>#', (string) $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    #[TestDox('Ningún filtro ni búsqueda amplía lo que cada persona ve: con todos los filtros y la búsqueda en mensajes, el resultado es siempre un SUBCONJUNTO de visibleTo (R1, R13)')]
    public function test_filters_never_widen_the_scope(): void
    {
        $contab = $this->conAsunto('Factura de contabilidad', Department::Other);
        $this->mensaje($contab, 'palabra-comun');
        $soporte = $this->conAsunto('Problema de correo', Department::Support, TicketStatus::InProgress, ['assigned_to' => $this->a['agSup']->id]);
        $this->mensaje($soporte, 'palabra-comun');
        $ajeno = Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'subject' => 'Ajeno palabra-comun']);
        $this->mensaje($ajeno, 'palabra-comun');

        $combinaciones = [
            ['q' => 'palabra-comun', 'en_mensajes' => 1],
            ['q' => 'Factura', 'en_mensajes' => 1, 'desde' => '2000-01-01'],
            ['empresa' => $this->empresaA->id, 'asignado' => 'sin', 'vivos' => 1],
            ['empresa' => $this->empresaB->id],
            ['departamento' => 'other'],
            ['quieta' => 3, 'orden' => 'numero'],
        ];

        foreach ($this->a as $clave => $actor) {
            $visibles = Ticket::visibleTo($actor)->pluck('id')->all();
            foreach ($combinaciones as $filtros) {
                $filtrados = TicketFilters::apply(Ticket::query()->visibleTo($actor), $filtros, $actor)->pluck('id')->all();
                $this->assertSame([], array_values(array_diff($filtrados, $visibles)), "$clave obtiene tickets que no puede ver con ".json_encode($filtros));
            }
        }

        // Y por la pantalla: un agente de Soporte buscando en mensajes NO obtiene el de Contabilidad (sí los demás); el de Contabilidad, solo ese.
        $this->assertEqualsCanonicalizing(['Problema de correo', 'Ajeno palabra-comun'], $this->asuntos('agSup', ['q' => 'palabra-comun', 'en_mensajes' => 1], equipo: true), 'el agente ve todo salvo Contabilidad (R11, R13)');
        $this->assertSame(['Factura de contabilidad'], $this->asuntos('agCon', ['q' => 'palabra-comun', 'en_mensajes' => 1], equipo: true));
        $this->assertEqualsCanonicalizing(['Factura de contabilidad', 'Problema de correo'], $this->asuntos('cuA', ['q' => 'palabra-comun', 'en_mensajes' => 1]));
    }

    #[TestDox('R2: el cliente que busca una palabra que SOLO está en una nota interna no encuentra el ticket (ni con la búsqueda en mensajes); el equipo sí')]
    public function test_client_never_finds_internal_notes(): void
    {
        $t = $this->conAsunto('Ticket con nota');
        $this->mensaje($t, 'palabra-publica');
        $this->mensaje($t, 'NOTA-SECRETA-XYZ', interno: true);

        foreach (['cuA', 'caA'] as $cliente) {
            $this->assertSame([], $this->asuntos($cliente, ['q' => 'NOTA-SECRETA-XYZ', 'en_mensajes' => 1]), "$cliente no debe encontrar la nota");
            $this->assertSame(['Ticket con nota'], $this->asuntos($cliente, ['q' => 'palabra-publica', 'en_mensajes' => 1]));
        }
        $this->assertSame(['Ticket con nota'], $this->asuntos('agSup', ['q' => 'NOTA-SECRETA-XYZ', 'en_mensajes' => 1], equipo: true));
    }

    #[TestDox('Sin la casilla, la búsqueda mira solo asunto/número (y persona y empresa en el equipo); con ella, también el texto de los mensajes')]
    public function test_message_search_is_optional(): void
    {
        $t = $this->conAsunto('Asunto limpio');
        $this->mensaje($t, 'texto-escondido');

        $this->assertSame([], $this->asuntos('cuA', ['q' => 'texto-escondido']));
        $this->assertSame(['Asunto limpio'], $this->asuntos('cuA', ['q' => 'texto-escondido', 'en_mensajes' => 1]));
        $this->assertSame(['Asunto limpio'], $this->asuntos('agSup', ['q' => $this->a['cuA']->name], equipo: true), 'el equipo sigue buscando por persona');
        $this->assertSame([], $this->asuntos('cuA', ['q' => $this->a['cuA']->name]), 'el cliente NO busca por persona');
    }

    #[TestDox('Los % y _ escritos en la búsqueda no son comodines (también en el texto de los mensajes)')]
    public function test_wildcards_are_escaped(): void
    {
        $a = $this->conAsunto('Uno');
        $this->mensaje($a, 'descuento del 100% total');
        $this->conAsunto('Dos');

        $this->assertSame(['Uno'], $this->asuntos('cuA', ['q' => '100%', 'en_mensajes' => 1]));
        $this->assertSame([], $this->asuntos('cuA', ['q' => '%', 'en_mensajes' => 1, 'estado' => 'closed']));
        $this->assertSame([], $this->asuntos('cuA', ['q' => '_', 'en_mensajes' => 1]), '«_» no debe casar con cualquier carácter');
    }

    #[TestDox('Los filtros de fechas, vivos, sin actividad, asignado, producto, categoría y estado restringen y se combinan con Y')]
    public function test_filters_restrict_and_combine(): void
    {
        $vieja = $this->conAsunto('Vieja quieta', extra: ['created_at' => now()->subDays(40), 'last_activity_at' => now()->subDays(20)]);
        $reciente = $this->conAsunto('Reciente', Department::Support, TicketStatus::InProgress, ['last_activity_at' => now(), 'assigned_to' => $this->a['agSup']->id]);
        $this->conAsunto('Resuelta antigua', Department::Support, TicketStatus::Resolved, ['last_activity_at' => now()->subDays(60)]);

        $this->assertEqualsCanonicalizing(['Vieja quieta', 'Reciente'], $this->asuntos('agSup', ['vivos' => 1], true));
        $this->assertSame(['Vieja quieta'], $this->asuntos('agSup', ['quieta' => 14], true), 'quieta implica sin resolver ni cerrar: la resuelta antigua no cuenta');
        $this->assertSame(['Reciente'], $this->asuntos('agSup', ['asignado' => $this->a['agSup']->id], true));
        $this->assertSame(['Vieja quieta'], $this->asuntos('agSup', ['asignado' => 'sin', 'vivos' => 1], true));
        $this->assertSame(['Vieja quieta'], $this->asuntos('agSup', ['hasta' => now()->subDays(30)->toDateString()], true));
        $this->assertContains('Reciente', $this->asuntos('agSup', ['hasta' => now()->toDateString()], true), '«hasta» incluye TODO el día elegido, no solo hasta las 00:00');
        $this->assertEqualsCanonicalizing(['Reciente', 'Resuelta antigua'], array_values(array_diff($this->asuntos('agSup', ['desde' => now()->subDays(10)->toDateString()], true), [])), 'desde: las creadas hace poco');
        $this->assertSame(['Reciente'], $this->asuntos('agSup', ['estado' => 'in_progress', 'asignado' => $this->a['agSup']->id, 'desde' => now()->subDays(10)->toDateString()], true), 'combinados con Y');
        $this->assertSame([], $this->asuntos('agSup', ['categoria' => $vieja->category_id, 'estado' => 'closed'], true));
        $this->assertContains('Vieja quieta', $this->asuntos('agSup', ['categoria' => $vieja->category_id], true));
    }

    #[TestDox('El orden por número, apertura y actividad ordena de verdad')]
    public function test_ordering(): void
    {
        $this->conAsunto('Primero', extra: ['year' => 2026, 'sequence' => 1, 'created_at' => now()->subDays(3), 'last_activity_at' => now()->subDays(1)]);
        $this->conAsunto('Segundo', extra: ['year' => 2026, 'sequence' => 2, 'created_at' => now()->subDays(1), 'last_activity_at' => now()->subDays(3)]);

        $this->assertSame(['Primero', 'Segundo'], $this->asuntos('cuA', []), 'por defecto: actividad reciente primero');
        $this->assertSame(['Segundo', 'Primero'], $this->asuntos('cuA', ['orden' => 'actividad_asc']));
        $this->assertSame(['Segundo', 'Primero'], $this->asuntos('cuA', ['orden' => 'apertura_desc']));
        $this->assertSame(['Segundo', 'Primero'], $this->asuntos('cuA', ['orden' => 'numero']));
    }

    #[TestDox('Los valores inválidos se rechazan con mensaje (sin error 500): estado, fechas cruzadas, plazo no ofrecido, orden y asignado manipulado')]
    public function test_invalid_values_are_rejected(): void
    {
        $equipo = $this->actingAs($this->a['agSup']);
        $equipo->get(route('inbox', ['estado' => 'inventado']))->assertSessionHasErrors('estado');
        $equipo->get(route('inbox', ['desde' => '2026-05-10', 'hasta' => '2026-05-01']))->assertSessionHasErrors('hasta');
        $equipo->get(route('inbox', ['desde' => 'ayer']))->assertSessionHasErrors('desde');
        $equipo->get(route('inbox', ['quieta' => 5]))->assertSessionHasErrors('quieta');
        $equipo->get(route('inbox', ['orden' => 'DROP TABLE']))->assertSessionHasErrors('orden');
        $equipo->get(route('inbox', ['asignado' => '1 OR 1=1']))->assertSessionHasErrors('asignado');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->a['cuA'])->get(route('tickets.index', ['quieta' => 5]))->assertOk(); // campo del equipo: ignorado
    }

    #[TestDox('Un cliente no puede usar filtros del equipo: empresa y asignado se ignoran y no devuelven nada ajeno')]
    public function test_client_cannot_use_team_filters(): void
    {
        $this->conAsunto('Mío');
        Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'subject' => 'De otra empresa']);

        $this->assertSame(['Mío'], $this->asuntos('cuA', ['empresa' => $this->empresaB->id, 'asignado' => 'sin', 'usuario' => $this->a['cuB']->id]));
        $this->assertSame(['Mío'], $this->asuntos('caA', ['empresa' => $this->empresaB->id]));
    }

    #[TestDox('«Abierto por» solo se aplica al administrador de empresa; al usuario normal no le amplía ni le reduce nada')]
    public function test_requester_filter_is_for_company_admin_only(): void
    {
        $this->conAsunto('De cuA');
        Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA2']->id, 'subject' => 'De cuA2']);

        $this->assertSame(['De cuA2'], $this->asuntos('caA', ['usuario' => $this->a['cuA2']->id]));
        $this->assertSame(['De cuA'], $this->asuntos('cuA', ['usuario' => $this->a['cuA2']->id]), 'el usuario normal sigue viendo solo lo suyo');
    }

    #[TestDox('La barra de filtros aplicados enseña cada filtro con su enlace para quitarlo, y NO revela el nombre de una empresa que la persona no ve')]
    public function test_active_filters_bar(): void
    {
        $this->conAsunto('Visible', Department::Support);
        Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'department' => 'other', 'subject' => 'De B contabilidad']);

        $this->app['auth']->forgetGuards();
        $html = $this->actingAs($this->a['agSup'])->get(route('inbox', ['q' => 'visi', 'en_mensajes' => 1, 'estado' => 'open', 'empresa' => $this->empresaB->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Filtros aplicados', (string) $html);
        $this->assertStringContainsString('Texto: «visi» (también en los mensajes y las notas)', (string) $html);
        $this->assertStringContainsString('Estado: Abierto', (string) $html);
        $this->assertStringContainsString('Empresa: —', (string) $html);
        $this->assertStringNotContainsString($this->empresaB->name, (string) $html, 'el agente de Soporte no ve ni el nombre de la empresa que solo tiene tickets de Contabilidad (R13)');
        // El enlace de quitar el estado conserva los demás filtros y no lleva el estado.
        preg_match('#aria-label="Quitar el filtro Estado: Abierto"#', (string) $html, $x);
        $this->assertNotEmpty($x);
        $this->assertMatchesRegularExpression('#href="[^"]*q=visi[^"]*"[^>]*aria-label="Quitar el filtro Estado#', str_replace('&amp;', '&', (string) $html));
    }

    #[TestDox('Las vistas rápidas marcan como actual la que coincide con lo que hay puesto, y «Todos» cuando no hay filtros')]
    public function test_quick_views_mark_current(): void
    {
        $this->app['auth']->forgetGuards();
        $agente = $this->actingAs($this->a['agSup']);

        $agente->get(route('inbox'))->assertOk()->assertSeeInOrder(['Todos', 'Mis tickets']);
        $html = $agente->get(route('inbox', ['asignado' => $this->a['agSup']->id, 'vivos' => 1]))->getContent();
        $this->assertMatchesRegularExpression('#aria-current="true"[^>]*>Mis tickets#', (string) $html);
        $this->assertDoesNotMatchRegularExpression('#aria-current="true"[^>]*>Todos#', (string) $html);

        $html = $agente->get(route('inbox'))->getContent();
        $this->assertMatchesRegularExpression('#aria-current="true"[^>]*>Todos#', (string) $html);

        $this->app['auth']->forgetGuards();
        $cliente = $this->actingAs($this->a['cuA']);
        $cliente->get(route('tickets.index'))->assertOk()->assertSee('Esperan tu respuesta')->assertDontSee('Mis tickets</a>', false)->assertDontSee('Sin actividad en 7 días');
    }

    #[TestDox('La paginación conserva los filtros y la búsqueda en mensajes')]
    public function test_pagination_keeps_filters(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->conAsunto("Lote $i", extra: ['sequence' => 500 + $i]);
        }

        $this->app['auth']->forgetGuards();
        $html = $this->actingAs($this->a['agSup'])->get(route('inbox', ['estado' => 'open', 'orden' => 'numero', 'q' => 'Lote', 'en_mensajes' => 1]))->getContent();

        $siguiente = null;
        preg_match('#<a href="([^"]+)" rel="next"#', (string) $html, $siguiente);
        $this->assertNotEmpty($siguiente, 'hay segunda página');
        $url = html_entity_decode($siguiente[1]);
        $this->assertStringContainsString('estado=open', $url);
        $this->assertStringContainsString('orden=numero', $url);
        $this->assertStringContainsString('en_mensajes=1', $url);
        $this->assertStringContainsString('q=Lote', $url);
    }

    #[TestDox('Con todos los filtros activos la bandeja no multiplica las consultas por ticket (sin N+1)')]
    public function test_no_n_plus_one_with_all_filters(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $t = $this->conAsunto("Con mensajes $i", Department::Support, TicketStatus::InProgress, ['assigned_to' => $this->a['agSup']->id]);
            $this->mensaje($t, 'hola');
        }
        $filtros = ['q' => 's', 'en_mensajes' => 1, 'estado' => 'in_progress', 'vivos' => 1, 'desde' => '2000-01-01', 'quieta' => 3, 'orden' => 'numero'];

        $contar = function (array $f): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->a['agSup'])->get(route('inbox', $f))->assertOk();

            return count(DB::getQueryLog());
        };

        $con8 = $contar($filtros);
        for ($i = 0; $i < 8; $i++) {
            $t = $this->conAsunto("Más $i", Department::Support, TicketStatus::InProgress, ['assigned_to' => $this->a['agSup']->id]);
            $this->mensaje($t, 'hola');
        }
        $this->assertSame($con8, $contar($filtros), 'más tickets no deben dar más consultas (la página son 10)');
    }
}
