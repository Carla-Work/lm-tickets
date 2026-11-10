<?php

/*
 * Acciones del equipo sobre un ticket: responder, nota interna, estado, asignar y cambiar de departamento.
 * Reglas: R2 (la nota no llega al cliente), R4/R15 (nadie reabre), R5 (todo deja evento), R10 (el administrador
 * solo gestiona «Otros»), R11 (cualquier agente asigna a cualquiera), R12 (cambio de departamento visible al
 * cliente), R13 (el agente no ve «Otros»), R16 (resolver exige explicar cómo).
 * Cuándo tocarlo: al cambiar esas reglas. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class StaffActionsTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    private function as(string $quien): static
    {
        return $this->actingAs($this->a[$quien]);
    }

    // ---------- responder y notas ----------

    #[TestDox('El agente asignado responde al cliente: mensaje público y el estado NO cambia solo')]
    public function test_assigned_agent_replies_without_status_change(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::PendingCustomer, 'agSup');

        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'Probad ahora'])->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(MessageKind::Public, TicketMessage::query()->where('ticket_id', $ticket->id)->firstOrFail()->kind);
        $this->assertSame(TicketStatus::PendingCustomer, $ticket->fresh()->status);
    }

    #[TestDox('Un agente que NO lleva el ticket no responde al cliente (403) pero sí puede dejar una nota; el administrador no responde fuera de «Otros» (R10)')]
    public function test_only_the_handler_replies(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agApp')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('admin')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agApp')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'visto desde Aplicaciones'])->assertRedirect();

        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(MessageKind::Internal, TicketMessage::query()->where('ticket_id', $ticket->id)->firstOrFail()->kind);
    }

    #[TestDox('En «Otros» el administrador responde y escribe notas; fuera de «Otros» sus notas se rechazan (R10)')]
    public function test_admin_handles_other_only(): void
    {
        $otros = $this->ticket(Department::Other, TicketStatus::Open);
        $normal = $this->ticket(Department::Support, TicketStatus::Open);

        $this->as('admin')->post(route('tickets.messages.store', $otros), ['mensaje' => 'Le llamo mañana'])->assertRedirect();
        $this->as('admin')->post(route('tickets.notes.store', $otros), ['mensaje' => 'nota'])->assertRedirect();
        $this->as('admin')->post(route('tickets.notes.store', $normal), ['mensaje' => 'nota'])->assertForbidden();

        $this->assertSame(2, TicketMessage::query()->where('ticket_id', $otros->id)->count());
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $normal->id)->count());
    }

    #[TestDox('R13: un agente no escribe ni deja notas en un ticket de «Otros»')]
    public function test_agent_cannot_write_in_other(): void
    {
        $ticket = $this->ticket(Department::Other, TicketStatus::Open);

        $this->as('agSup')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agSup')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
    }

    #[TestDox('R2: la nota interna nunca llega al cliente: ni por la ruta de notas, ni en su detalle; y un cliente no puede crear notas')]
    public function test_note_never_reaches_the_client(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $this->as('agSup')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'NOTA-RESERVADA-77']);

        foreach (['cuA', 'caA'] as $cliente) {
            $this->as($cliente)->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('NOTA-RESERVADA-77');
            $this->as($cliente)->post(route('tickets.notes.store', $ticket), ['mensaje' => 'intento'])->assertForbidden();
        }
        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    #[TestDox('R4: en un ticket «Cerrado» nadie escribe; en «Resuelto» el agente que lo lleva aún puede responder y anotar')]
    public function test_closed_is_silent_resolved_is_not(): void
    {
        $cerrado = $this->ticket(Department::Support, TicketStatus::Closed, 'agSup');
        $resuelto = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');

        $this->as('agSup')->post(route('tickets.messages.store', $cerrado), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agSup')->post(route('tickets.notes.store', $cerrado), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agSup')->post(route('tickets.messages.store', $resuelto), ['mensaje' => 'Una guía extra'])->assertRedirect();
    }

    // ---------- estado ----------

    #[TestDox('El agente que lleva el ticket cambia el estado y queda el evento (R5)')]
    public function test_handler_changes_status(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');

        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'pending_customer'])->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(TicketStatus::PendingCustomer, $ticket->fresh()->status);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::StatusChanged->value)->firstOrFail();
        $this->assertEquals(['from' => 'open', 'to' => 'pending_customer'], $evento->data); // MySQL reordena las claves del JSON
        $this->assertSame($this->a['agSup']->id, $evento->actor_id);
    }

    #[TestDox('R15: nadie reabre ni retrocede: los saltos no permitidos se rechazan y el estado no cambia')]
    public function test_invalid_transitions_are_rejected(): void
    {
        $casos = [
            [TicketStatus::Resolved, 'in_progress'], [TicketStatus::Resolved, 'open'], [TicketStatus::Closed, 'in_progress'],
            [TicketStatus::Closed, 'open'], [TicketStatus::InProgress, 'open'], [TicketStatus::Open, 'closed'],
        ];
        foreach ($casos as [$desde, $hacia]) {
            $ticket = $this->ticket(Department::Support, $desde, 'agSup');

            $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => $hacia]);

            $this->assertSame($desde, $ticket->fresh()->status, "{$desde->value} → $hacia no debe cambiar");
        }
    }

    #[TestDox('R16: el equipo no puede resolver sin explicar cómo; con explicación se guarda y es visible al cliente')]
    public function test_staff_resolution_requires_explanation(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => '  '])->assertSessionHasErrors('solucion');
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);

        $this->as('agSup')->post(route('tickets.status.update', $ticket), ['estado' => 'resolved', 'solucion' => 'Cambiado el cable'])->assertRedirect();
        $this->assertSame('Cambiado el cable', $ticket->fresh()->resolution);
        $this->as('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertSee('Cambiado el cable');
    }

    #[TestDox('Cerrar solo se puede desde «Resuelto» y lo hace quien lo lleva; fija la fecha de cierre')]
    public function test_close_only_from_resolved(): void
    {
        $abierto = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $resuelto = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');

        $this->as('agSup')->post(route('tickets.status.update', $abierto), ['estado' => 'closed']);
        $this->as('agApp')->post(route('tickets.status.update', $resuelto), ['estado' => 'closed'])->assertForbidden();
        $this->as('agSup')->post(route('tickets.status.update', $resuelto), ['estado' => 'closed'])->assertRedirect();

        $this->assertSame(TicketStatus::InProgress, $abierto->fresh()->status);
        $this->assertSame(TicketStatus::Closed, $resuelto->fresh()->status);
        $this->assertNotNull($resuelto->fresh()->closed_at);
    }

    #[TestDox('Quien no lleva el ticket, el administrador fuera de «Otros» y los clientes no cambian el estado (403)')]
    public function test_others_cannot_change_status(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');

        foreach (['agApp', 'admin', 'cuA', 'caA'] as $quien) {
            $this->as($quien)->post(route('tickets.status.update', $ticket), ['estado' => 'in_progress'])->assertForbidden();
        }
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    // ---------- asignar ----------

    #[TestDox('R11: un agente asigna a un agente del OTRO equipo, queda el evento con los nombres y el cliente no lo ve (R5, R2)')]
    public function test_cross_team_assignment_is_recorded_and_internal(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agApp']->id])->assertRedirect();

        $this->assertSame($this->a['agApp']->id, $ticket->fresh()->assigned_to);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Assigned->value)->firstOrFail();
        $this->assertSame('Agente Soporte', $evento->data['from_name']);
        $this->assertSame('Agente Aplicaciones', $evento->data['to_name']);
        $this->assertFalse($evento->visible_to_customer);
        // El cliente ve QUIÉN le atiende, pero no el rastro interno de la reasignación.
        $this->as('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('reasignó')->assertDontSee('Agente Soporte');
    }

    #[TestDox('«Asignármelo»: un agente que no lleva el ticket se lo asigna y desde ese momento puede responder')]
    public function test_assign_to_myself_enables_reply(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open);

        $this->as('agApp')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agApp')->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agApp']->id])->assertRedirect();
        $this->as('agApp')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'Ya lo miro yo'])->assertRedirect();
    }

    #[TestDox('El administrador reasigna fuera de «Otros» pero no en «Otros»; los clientes no asignan')]
    public function test_admin_assigns_outside_other_only(): void
    {
        $normal = $this->ticket(Department::Support, TicketStatus::Open);
        $otros = $this->ticket(Department::Other, TicketStatus::Open);

        $this->as('admin')->post(route('tickets.assignment.update', $normal), ['asignado' => $this->a['agSup']->id])->assertRedirect();
        $this->as('admin')->post(route('tickets.assignment.update', $otros), ['asignado' => $this->a['agSup']->id])->assertForbidden();
        $this->as('cuA')->post(route('tickets.assignment.update', $normal), ['asignado' => $this->a['agSup']->id])->assertForbidden();

        $this->assertSame($this->a['agSup']->id, $normal->fresh()->assigned_to);
        $this->assertNull($otros->fresh()->assigned_to);
    }

    #[TestDox('Solo se asigna a un agente en activo: no a un cliente, ni al administrador, ni a una cuenta de baja, ni a un id inventado')]
    public function test_only_active_agents_can_be_assignees(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::Open);
        $baja = User::factory()->agent()->create(['deactivated_at' => now()]);

        foreach ([$this->a['cuA']->id, $this->a['admin']->id, $baja->id, 999999, 'abc'] as $destino) {
            $this->as('agSup')->post(route('tickets.assignment.update', $ticket), ['asignado' => $destino])->assertSessionHasErrors('asignado');
        }
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    #[TestDox('Dejar un ticket «sin asignar» funciona y asignarlo a quien ya lo lleva se rechaza; en un ticket final no se reasigna')]
    public function test_unassign_same_and_final(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->post(route('tickets.assignment.update', $ticket), ['asignado' => $this->a['agSup']->id])->assertSessionHasErrors('asignado');
        $this->as('agSup')->post(route('tickets.assignment.update', $ticket), ['asignado' => 'sin'])->assertRedirect();
        $this->assertNull($ticket->fresh()->assigned_to);

        $final = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');
        $this->as('agSup')->post(route('tickets.assignment.update', $final), ['asignado' => $this->a['agApp']->id])->assertForbidden();
    }

    // ---------- departamento ----------

    private function categoria(Department $departamento): Category
    {
        return Category::factory()->forDepartment($departamento)->create();
    }

    #[TestDox('R12: un agente cambia el departamento (con categoría del nuevo), queda el evento y el CLIENTE lo ve en su historial')]
    public function test_department_change_is_visible_to_the_client(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $categoria = $this->categoria(Department::WebApps);

        $this->as('agApp')->post(route('tickets.department.update', $ticket), ['departamento' => 'web_apps', 'categoria' => $categoria->id])
            ->assertRedirect(route('tickets.show', $ticket));

        $ticket = $ticket->fresh();
        $this->assertSame(Department::WebApps, $ticket->department);
        $this->assertSame($categoria->id, $ticket->category_id);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::DepartmentChanged->value)->firstOrFail();
        $this->assertTrue($evento->visible_to_customer);
        $this->assertSame($this->a['agSup']->id, $ticket->assigned_to, 'la asignación se mantiene al cambiar a otro equipo (decidido)');
        $this->as('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertSee('cambiado el departamento');
    }

    #[TestDox('R6: la categoría debe ser del departamento nuevo y estar activa; el mismo departamento se rechaza')]
    public function test_department_requires_matching_category(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $deOtroDep = $this->categoria(Department::Websites);
        $inactiva = Category::factory()->forDepartment(Department::WebApps)->create(['active' => false]);
        $dePropio = $this->categoria(Department::Support);

        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['departamento' => 'web_apps', 'categoria' => $deOtroDep->id])->assertSessionHasErrors('categoria');
        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['departamento' => 'web_apps', 'categoria' => $inactiva->id])->assertSessionHasErrors('categoria');
        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['departamento' => 'support', 'categoria' => $dePropio->id])->assertSessionHasErrors('departamento');

        $this->assertSame(Department::Support, $ticket->fresh()->department);
    }

    #[TestDox('Pasar un ticket a «Otros» vacía la asignación, deja los dos eventos y el agente va a la bandeja (ya no lo ve, R13)')]
    public function test_moving_to_other_clears_assignment_and_hides_it_from_agents(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $categoria = $this->categoria(Department::Other);

        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['departamento' => 'other', 'categoria' => $categoria->id])
            ->assertRedirect(route('inbox'));

        $this->assertNull($ticket->fresh()->assigned_to);
        $retirada = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Assigned->value)->firstOrFail();
        $this->assertSame('moved_to_other', $retirada->data['reason']);
        $this->assertFalse($retirada->visible_to_customer);
        $this->as('agSup')->get(route('tickets.show', $ticket))->assertForbidden();
        $this->as('admin')->get(route('tickets.show', $ticket))->assertOk();
    }

    #[TestDox('El administrador solo reclasifica los de «Otros» (R10, R12): puede sacarlos de ahí, y no mover uno normal')]
    public function test_admin_reclassifies_only_other(): void
    {
        $otros = $this->ticket(Department::Other, TicketStatus::Open);
        $normal = $this->ticket(Department::Support, TicketStatus::Open);
        $categoria = $this->categoria(Department::WebApps);

        $this->as('admin')->post(route('tickets.department.update', $normal), ['departamento' => 'web_apps', 'categoria' => $categoria->id])->assertForbidden();
        $this->as('admin')->post(route('tickets.department.update', $otros), ['departamento' => 'web_apps', 'categoria' => $categoria->id])->assertRedirect(route('tickets.show', $otros));

        $this->assertSame(Department::WebApps, $otros->fresh()->department);
        $this->assertNull($otros->fresh()->assigned_to, 'al sacarlo de «Otros» queda sin asignar');
    }

    #[TestDox('Los clientes no cambian el departamento (403) y en un ticket final nadie lo cambia')]
    public function test_clients_and_final_tickets_cannot_change_department(): void
    {
        $categoria = $this->categoria(Department::WebApps);
        $vivo = $this->ticket(Department::Support, TicketStatus::Open);
        $final = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');

        $this->as('cuA')->post(route('tickets.department.update', $vivo), ['departamento' => 'web_apps', 'categoria' => $categoria->id])->assertForbidden();
        $this->as('agSup')->post(route('tickets.department.update', $final), ['departamento' => 'web_apps', 'categoria' => $categoria->id])->assertForbidden();
    }

    // ---------- lo que se ve ----------

    #[TestDox('El detalle del equipo ofrece solo los controles que la policy permite: el agente ajeno ve «Asignármelo» y no «Enviar al cliente»')]
    public function test_detail_offers_controls_by_policy(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Enviar al cliente')->assertSee('Guardar nota')->assertSee('Cambiar estado')->assertSee('Reasignar')->assertSee('Cambiar departamento')->assertDontSee('Asignármelo');
        $this->as('agApp')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Asignármelo')->assertSee('Guardar nota')->assertDontSee('Enviar al cliente')->assertDontSee('Cambiar estado');
        $this->as('admin')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Reasignar')->assertDontSee('Enviar al cliente')->assertDontSee('Guardar nota')->assertDontSee('Cambiar departamento');
    }
}
