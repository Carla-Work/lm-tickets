<?php

/*
 * Prioridad interna y etiquetas internas de los tickets (P1; docs/plan/p1-prioridades-y-etiquetas.md).
 * Cuándo tocarlo: al cambiar quién puede cambiarlas o qué ve cada rol. Lo que de verdad vigila:
 *  - el CLIENTE no ve nunca ni la prioridad ni las etiquetas (ni en listas, detalle, avisos, filtros u orden);
 *  - solo un agente que atiende el departamento las cambia (cliente y agente de otro equipo, no). La PRIORIDAD también la fija el administrador (2026-11-11); las etiquetas, no;
 *  - cada cambio deja evento INTERNO y no toca la fecha de actividad (que ordena la lista del cliente).
 * Si falla, se arregla el código o se discute la regla: NO se adapta el test.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\Priority;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class PriorityAndTagsTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function como(string $quien): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->a[$quien]);
    }

    private function ticketDe(Department $dep = Department::Support, TicketStatus $estado = TicketStatus::InProgress, array $extra = []): Ticket
    {
        return Ticket::factory()->create(array_merge(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA']->id, 'department' => $dep->value, 'status' => $estado->value, 'subject' => 'Asunto '.fake()->unique()->word()], $extra));
    }

    /** @return list<string> asuntos de la lista de esa persona */
    private function asuntos(string $quien, array $f, bool $equipo): array
    {
        $html = $this->como($quien)->get(route($equipo ? 'inbox' : 'tickets.index', $f))->assertOk()->getContent();
        preg_match_all('#class="asunto" href="[^"]+">([^<]+)</a>#', (string) $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    #[TestDox('Un ticket nuevo entra como «Normal», también recién creado en memoria; la base rechaza cualquier otro valor')]
    public function test_default_priority_and_check_constraint(): void
    {
        $ticket = $this->ticketDe();
        $this->assertSame(Priority::Normal, $ticket->priority);
        $this->assertSame(Priority::Normal, $ticket->fresh()->priority);

        $this->expectException(QueryException::class);
        DB::table('tickets')->where('id', $ticket->id)->update(['priority' => 'critica']);
    }

    #[TestDox('El agente que atiende el departamento cambia la prioridad: queda evento INTERNO con de→a, sin avisos y SIN tocar la fecha de actividad')]
    public function test_agent_changes_priority(): void
    {
        Notification::fake();
        $ticket = $this->ticketDe(extra: ['last_activity_at' => now()->subDays(5)]);
        $antes = $ticket->fresh()->last_activity_at;

        $this->como('agSup')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'urgent'])->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(Priority::Urgent, $ticket->fresh()->priority);
        $this->assertEquals($antes, $ticket->fresh()->last_activity_at, 'la prioridad es interna: no mueve la actividad (ordena la lista del cliente)');
        $evento = TicketEvent::where('ticket_id', $ticket->id)->where('type', TicketEventType::PriorityChanged->value)->firstOrFail();
        $this->assertEquals(['from' => 'normal', 'to' => 'urgent'], $evento->data);
        $this->assertFalse($evento->visible_to_customer);
        $this->assertSame($this->a['agSup']->id, $evento->actor_id);
        Notification::assertNothingSent();

        // Otro agente del otro equipo también puede (cualquier agente que atienda el departamento, R12).
        $this->como('agApp')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'low'])->assertRedirect();
        $this->assertSame(Priority::Low, $ticket->fresh()->priority);
    }

    #[TestDox('El administrador fija la prioridad en cualquier ticket no final (también en Contabilidad): evento INTERNO a su nombre, sin avisos ni mover la actividad; pero NO toca las etiquetas')]
    public function test_admin_changes_priority_but_not_tags(): void
    {
        Notification::fake();
        $etiqueta = Tag::factory()->create();
        $soporte = $this->ticketDe(extra: ['last_activity_at' => now()->subDays(5)]);
        $antes = $soporte->fresh()->last_activity_at;
        $contabilidad = $this->ticketDe(Department::Other);

        foreach ([$soporte, $contabilidad] as $ticket) {
            $this->como('admin')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'urgent'])->assertRedirect(route('tickets.show', $ticket));
            $this->assertSame(Priority::Urgent, $ticket->fresh()->priority);
            $evento = TicketEvent::where('ticket_id', $ticket->id)->where('type', TicketEventType::PriorityChanged->value)->firstOrFail();
            $this->assertSame($this->a['admin']->id, $evento->actor_id);
            $this->assertFalse($evento->visible_to_customer);

            // Etiquetas: sigue siendo cosa del agente (R10: el administrador no gestiona).
            $this->como('admin')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$etiqueta->id]])->assertForbidden();
            $this->assertSame(0, $ticket->tags()->count());
        }
        $this->assertEquals($antes, $soporte->fresh()->last_activity_at, 'la prioridad es interna: no mueve la actividad');
        Notification::assertNothingSent();

        // En un ticket final, ni el administrador.
        $cerrado = $this->ticketDe(estado: TicketStatus::Closed);
        $this->como('admin')->post(route('tickets.priority.update', $cerrado), ['prioridad' => 'low'])->assertForbidden();
    }

    #[TestDox('Ni el cliente, ni el administrador de empresa, ni el agente de Contabilidad (en Soporte) pueden cambiar prioridad ni etiquetas, y el administrador no cambia etiquetas: 403, sin mensajes de validación aunque los datos sean inválidos')]
    public function test_everyone_else_is_forbidden(): void
    {
        $ticket = $this->ticketDe();
        $etiqueta = Tag::factory()->create();

        $this->como('admin')->post(route('tickets.tags.update', $ticket), ['etiquetas' => ['no-es-un-id']])->assertForbidden();

        foreach (['cuA', 'caA', 'cuB', 'agCon'] as $quien) {
            $this->como($quien)->post(route('tickets.priority.update', $ticket), ['prioridad' => 'inventada'])->assertForbidden();
            $this->como($quien)->post(route('tickets.tags.update', $ticket), ['etiquetas' => ['no-es-un-id']])->assertForbidden();
            $this->como($quien)->post(route('tickets.priority.update', $ticket), ['prioridad' => 'urgent'])->assertForbidden();
            $this->como($quien)->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$etiqueta->id]])->assertForbidden();
        }
        $this->assertSame(Priority::Normal, $ticket->fresh()->priority);
        $this->assertSame(0, $ticket->tags()->count());
    }

    #[TestDox('En un ticket resuelto o cerrado no se cambian ni prioridad ni etiquetas (ni el agente)')]
    public function test_final_tickets_are_locked(): void
    {
        $etiqueta = Tag::factory()->create();
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $estado) {
            $ticket = $this->ticketDe(estado: $estado);
            $this->como('agSup')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'high'])->assertForbidden();
            $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$etiqueta->id]])->assertForbidden();
        }
    }

    #[TestDox('Valores inválidos (prioridad inventada o ya puesta) se rechazan con mensaje al agente, sin error 500')]
    public function test_invalid_priority_values(): void
    {
        $ticket = $this->ticketDe();

        $this->como('agSup')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'inventada'])->assertSessionHasErrors('prioridad');
        $this->como('agSup')->post(route('tickets.priority.update', $ticket), [])->assertSessionHasErrors('prioridad');
        $this->como('agSup')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'normal'])->assertSessionHasErrors('prioridad');
        $this->assertSame(0, TicketEvent::where('type', TicketEventType::PriorityChanged->value)->count());
    }

    #[TestDox('Etiquetas: se ponen y se quitan, el evento interno dice cuáles, y repetir lo mismo no crea evento')]
    public function test_tags_added_and_removed(): void
    {
        $ticket = $this->ticketDe();
        [$a, $b, $c] = Tag::factory()->count(3)->create()->all();

        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$a->id, $b->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ticket->tags()->pluck('tags.id')->all());

        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$b->id, $c->id]])->assertRedirect();
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ticket->tags()->pluck('tags.id')->all());
        $ultimo = TicketEvent::where('ticket_id', $ticket->id)->where('type', TicketEventType::TagsChanged->value)->latest('id')->firstOrFail();
        $this->assertSame([$c->name], array_column($ultimo->data['added'], 'name'));
        $this->assertSame([$a->name], array_column($ultimo->data['removed'], 'name'));
        $this->assertFalse($ultimo->visible_to_customer);

        $eventos = TicketEvent::where('type', TicketEventType::TagsChanged->value)->count();
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$c->id, $b->id]])->assertRedirect();
        $this->assertSame($eventos, TicketEvent::where('type', TicketEventType::TagsChanged->value)->count(), 'sin cambios no hay evento');

        // Todas desmarcadas = quitar todas (el formulario no envía el campo).
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), [])->assertRedirect();
        $this->assertSame(0, $ticket->tags()->count());
    }

    #[TestDox('No se puede poner una etiqueta inexistente, desactivada o pasar de 5; una ya puesta que se desactiva después sí se conserva')]
    public function test_tag_validation(): void
    {
        $ticket = $this->ticketDe();
        $desactivada = Tag::factory()->inactive()->create();
        $seis = Tag::factory()->count(6)->create();

        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [99999]])->assertSessionHasErrors('etiquetas');
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$desactivada->id]])->assertSessionHasErrors('etiquetas');
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => $seis->pluck('id')->all()])->assertSessionHasErrors('etiquetas');
        $this->assertSame(0, $ticket->tags()->count());

        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => $seis->take(5)->pluck('id')->all()])->assertRedirect();
        $this->assertSame(5, $ticket->tags()->count());

        // Desactivar una etiqueta puesta no se la quita al ticket, y guardar sin tocarla la mantiene.
        $seis->first()->forceFill(['active' => false])->save();
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => $seis->take(5)->pluck('id')->all()])->assertRedirect();
        $this->assertCount(5, $ticket->tags()->get());
    }

    #[TestDox('El CLIENTE no ve nunca la prioridad ni las etiquetas: ni en su lista, ni en el detalle (con el historial), ni en los avisos, ni como filtro u orden')]
    public function test_client_never_sees_priority_or_tags(): void
    {
        $etiqueta = Tag::factory()->create(['name' => 'ETIQUETA-SECRETA-QWERTY']);
        $alta = $this->ticketDe(extra: ['subject' => 'Ticket con prioridad']);
        $this->ticketDe(extra: ['subject' => 'Ticket normal']);
        $this->como('agSup')->post(route('tickets.priority.update', $alta), ['prioridad' => 'urgent']);
        $this->como('agSup')->post(route('tickets.tags.update', $alta), ['etiquetas' => [$etiqueta->id]]);

        $paginas = [
            fn () => $this->como('cuA')->get(route('tickets.index')),
            fn () => $this->como('caA')->get(route('tickets.index')),
            fn () => $this->como('cuA')->get(route('tickets.show', $alta)),
            fn () => $this->como('caA')->get(route('tickets.show', $alta)),
            fn () => $this->como('cuA')->get(route('notices.index')),
        ];
        foreach ($paginas as $i => $pagina) {
            $html = (string) $pagina()->assertOk()->getContent();
            foreach (['ETIQUETA-SECRETA-QWERTY', 'Urgente', 'class="prio', 'class="etq', 'cambió la prioridad', 'etiquetas'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $html, "la página $i del cliente enseña «{$prohibido}»");
            }
        }

        // Filtros y orden del equipo: el cliente no los tiene; ignorados u rechazados, nunca filtran por ellos.
        $this->assertEqualsCanonicalizing(['Ticket con prioridad', 'Ticket normal'], $this->asuntos('cuA', ['prioridad' => 'urgent', 'etiqueta' => $etiqueta->id], false));
        $this->como('cuA')->get(route('tickets.index', ['orden' => 'prioridad']))->assertSessionHasErrors('orden');
        $this->como('cuA')->get(route('tickets.index'))->assertDontSee('Prioridad (urgentes primero)');
    }

    #[TestDox('El detalle del equipo enseña prioridad y etiquetas; el agente tiene los dos formularios; el administrador solo el de prioridad')]
    public function test_internal_detail(): void
    {
        $etiqueta = Tag::factory()->create(['name' => 'Requiere visita']);
        $ticket = $this->ticketDe();
        $this->como('agSup')->post(route('tickets.priority.update', $ticket), ['prioridad' => 'high']);
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$etiqueta->id]]);

        $this->como('agApp')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('▲ Alta')->assertSee('Requiere visita')->assertSee('Cambiar prioridad')->assertSee('Guardar etiquetas')
            ->assertSee('cambió la prioridad de')->assertSee('añadió la etiqueta');

        $this->como('admin')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('▲ Alta')->assertSee('Requiere visita')->assertSee('Cambiar prioridad')->assertDontSee('Guardar etiquetas');
    }

    #[TestDox('Los nombres de etiqueta se escapan (también en el historial): un nombre con HTML no se ejecuta')]
    public function test_tag_names_are_escaped(): void
    {
        $etiqueta = Tag::factory()->create(['name' => '<b>x</b>']);
        $ticket = $this->ticketDe();
        $this->como('agSup')->post(route('tickets.tags.update', $ticket), ['etiquetas' => [$etiqueta->id]]);

        $html = (string) $this->como('agSup')->get(route('tickets.show', $ticket))->assertOk()->getContent();
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    #[TestDox('Bandeja: filtros de prioridad (incluida «alta o urgente») y etiqueta, orden por prioridad y vista rápida; el agente solo filtra sobre lo que ve (R13)')]
    public function test_inbox_filters_and_order(): void
    {
        $etiqueta = Tag::factory()->create();
        $this->ticketDe(extra: ['subject' => 'Baja', 'priority' => 'low']);
        $this->ticketDe(extra: ['subject' => 'Normal']);
        $this->ticketDe(extra: ['subject' => 'Alta', 'priority' => 'high']);
        $urgente = $this->ticketDe(extra: ['subject' => 'Urgente', 'priority' => 'urgent']);
        $this->ticketDe(Department::Other, extra: ['subject' => 'Urgente de contabilidad', 'priority' => 'urgent']);
        $urgente->tags()->attach($etiqueta->id);

        $this->assertSame(['Urgente'], $this->asuntos('agSup', ['prioridad' => 'urgent'], true), 'el agente de Soporte no ve el urgente de Contabilidad');
        $this->assertEqualsCanonicalizing(['Alta', 'Urgente'], $this->asuntos('agSup', ['prioridad' => 'high_urgent'], true));
        $this->assertSame(['Urgente'], $this->asuntos('agSup', ['etiqueta' => $etiqueta->id], true));
        $this->assertSame(['Urgente', 'Alta', 'Normal', 'Baja'], $this->asuntos('agSup', ['orden' => 'prioridad'], true));
        $this->assertSame(['Urgente de contabilidad'], $this->asuntos('agCon', ['prioridad' => 'urgent'], true));

        $html = (string) $this->como('agSup')->get(route('inbox', ['prioridad' => 'high_urgent', 'vivos' => 1]))->getContent();
        $this->assertMatchesRegularExpression('#aria-current="true"[^>]*>Urgentes y altas#', $html);
        $this->assertStringContainsString('Prioridad: alta o urgente', strip_tags($html));

        $this->como('agSup')->get(route('inbox', ['prioridad' => 'critica']))->assertSessionHasErrors('prioridad');
    }

    #[TestDox('Catálogo de etiquetas: solo el administrador crea, renombra y desactiva; los demás reciben 403 sin mensajes de validación; los nombres son únicos')]
    public function test_tag_catalog(): void
    {
        $this->como('admin')->get(route('admin.tags.index'))->assertOk()->assertSee('Etiquetas');
        $this->como('admin')->post(route('admin.tags.store'), ['name' => 'Facturable'])->assertRedirect(route('admin.tags.index'));
        $tag = Tag::where('name', 'Facturable')->firstOrFail();
        $this->assertTrue(AuditLog::where('event', 'tag_created')->exists());

        $this->como('admin')->post(route('admin.tags.store'), ['name' => 'Facturable'])->assertSessionHasErrors('name');
        $this->como('admin')->post(route('admin.tags.store'), ['name' => str_repeat('x', 41)])->assertSessionHasErrors('name');
        $this->como('admin')->post(route('admin.tags.update', $tag), ['name' => 'Cobrable'])->assertRedirect();
        $this->assertSame('Cobrable', $tag->fresh()->name);
        $this->assertTrue(AuditLog::where('event', 'tag_renamed')->exists());

        $this->como('admin')->post(route('admin.tags.status', $tag), ['activo' => 0])->assertRedirect();
        $this->assertFalse($tag->fresh()->active);
        $this->como('admin')->post(route('admin.tags.status', $tag), ['activo' => 1])->assertRedirect();
        $this->assertTrue($tag->fresh()->active);

        foreach (['agSup', 'agCon', 'cuA', 'caA'] as $quien) {
            $this->como($quien)->get(route('admin.tags.index'))->assertForbidden();
            $this->como($quien)->post(route('admin.tags.store'), ['name' => 'Facturable'])->assertForbidden(); // duplicado a propósito: no debe filtrar «ya existe»
            $this->como($quien)->post(route('admin.tags.update', $tag), ['name' => ''])->assertForbidden();
            $this->como($quien)->post(route('admin.tags.status', $tag), ['activo' => 0])->assertForbidden();
        }
        $this->assertTrue($tag->fresh()->active);
        $this->assertSame('Cobrable', $tag->fresh()->name);
    }

    #[TestDox('Una etiqueta en uso no se borra (la base lo impide con RESTRICT) y desactivarla no se la quita a los tickets')]
    public function test_used_tag_is_never_deleted(): void
    {
        $tag = Tag::factory()->create();
        $ticket = $this->ticketDe();
        $ticket->tags()->attach($tag->id);

        $this->como('admin')->post(route('admin.tags.status', $tag), ['activo' => 0])->assertRedirect();
        $this->assertSame(1, $ticket->tags()->count());

        $this->expectException(QueryException::class);
        DB::table('tags')->where('id', $tag->id)->delete();
    }

    #[TestDox('El menú de Administración tiene «Etiquetas» solo para el administrador')]
    public function test_menu_entry(): void
    {
        $this->como('admin')->get(route('inbox'))->assertOk()->assertSee(route('admin.tags.index'), false);
        $this->como('agSup')->get(route('inbox'))->assertOk()->assertDontSee(route('admin.tags.index'), false);
    }
}
