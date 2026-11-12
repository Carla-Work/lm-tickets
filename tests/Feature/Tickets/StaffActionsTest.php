<?php

/*
 * Acciones del equipo sobre un ticket: responder, nota interna, estado, asignar y cambiar de departamento.
 * Reglas: R2 (la nota no llega al cliente), R4/R15 (nadie reabre), R5 (todo deja evento), R10 (el administrador
 * nunca responde), R11 (cualquier agente asigna a quien pueda atender el departamento), R12 (cambio de departamento
 * visible al cliente), R13 (Contabilidad solo la ve su equipo y el administrador), R16 (resolver exige explicar cómo).
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

    #[TestDox('Un agente que NO lleva el ticket no responde al cliente (403) pero sí puede dejar una nota; el administrador no responde en ningún departamento (R10)')]
    public function test_only_the_handler_replies(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agApp')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('admin')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agApp')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'visto desde Aplicaciones'])->assertRedirect();

        $this->assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(MessageKind::Internal, TicketMessage::query()->where('ticket_id', $ticket->id)->firstOrFail()->kind);
    }

    #[TestDox('En Contabilidad responde su agente asignado; el administrador no responde ni deja notas en ningún departamento (R10, revisada 2026-11-10)')]
    public function test_accounting_agent_handles_and_admin_does_not(): void
    {
        $conta = $this->ticket(Department::Other, TicketStatus::Open, 'agCon');
        $normal = $this->ticket(Department::Support, TicketStatus::Open);

        $this->as('agCon')->post(route('tickets.messages.store', $conta), ['mensaje' => 'Le paso el presupuesto'])->assertRedirect();
        $this->as('agCon')->post(route('tickets.notes.store', $conta), ['mensaje' => 'nota'])->assertRedirect();
        foreach ([$conta, $normal] as $ticket) {
            $this->as('admin')->post(route('tickets.messages.store', $ticket), ['mensaje' => 'x'])->assertForbidden();
            $this->as('admin')->post(route('tickets.notes.store', $ticket), ['mensaje' => 'nota'])->assertForbidden();
        }

        $this->assertSame(2, TicketMessage::query()->where('ticket_id', $conta->id)->count());
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $normal->id)->count());
    }

    #[TestDox('R13: los agentes de Soporte y Desarrollo y Diseño no escriben ni dejan notas en Contabilidad, y el de Contabilidad no escribe en los demás departamentos')]
    public function test_agents_cannot_write_outside_their_scope(): void
    {
        $conta = $this->ticket(Department::Other, TicketStatus::Open);
        $soporte = $this->ticket(Department::Support, TicketStatus::Open, 'agCon'); // asignado a quien no puede atenderlo

        foreach (['agSup', 'agApp'] as $agente) {
            $this->as($agente)->post(route('tickets.messages.store', $conta), ['mensaje' => 'x'])->assertForbidden();
            $this->as($agente)->post(route('tickets.notes.store', $conta), ['mensaje' => 'x'])->assertForbidden();
        }
        $this->as('agCon')->post(route('tickets.messages.store', $soporte), ['mensaje' => 'x'])->assertForbidden();
        $this->as('agCon')->post(route('tickets.notes.store', $soporte), ['mensaje' => 'x'])->assertForbidden();
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

    #[TestDox('Quien no lleva el ticket, el administrador y los clientes no cambian el estado (403)')]
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

    #[TestDox('Se asigna solo a quien puede atender el departamento: Contabilidad solo a su equipo y su equipo solo Contabilidad; el administrador reasigna en todos (R11)')]
    public function test_assignment_respects_who_can_attend(): void
    {
        $soporte = $this->ticket(Department::Support, TicketStatus::Open);
        $conta = $this->ticket(Department::Other, TicketStatus::Open);

        $this->as('admin')->post(route('tickets.assignment.update', $soporte), ['asignado' => $this->a['agApp']->id])->assertRedirect();
        $this->as('admin')->post(route('tickets.assignment.update', $conta), ['asignado' => $this->a['agCon']->id])->assertRedirect();
        $this->assertSame($this->a['agApp']->id, $soporte->fresh()->assigned_to);
        $this->assertSame($this->a['agCon']->id, $conta->fresh()->assigned_to);

        // Lo que NO vale: un ticket de Contabilidad a otro equipo, o uno de otro departamento a Contabilidad.
        $otro = $this->ticket(Department::Other, TicketStatus::Open);
        $this->as('admin')->post(route('tickets.assignment.update', $otro), ['asignado' => $this->a['agSup']->id])->assertSessionHasErrors('asignado');
        $this->as('agCon')->post(route('tickets.assignment.update', $otro), ['asignado' => $this->a['agApp']->id])->assertSessionHasErrors('asignado');
        $libre = $this->ticket(Department::Websites, TicketStatus::Open);
        $this->as('admin')->post(route('tickets.assignment.update', $libre), ['asignado' => $this->a['agCon']->id])->assertSessionHasErrors('asignado');
        $this->as('cuA')->post(route('tickets.assignment.update', $libre), ['asignado' => $this->a['agSup']->id])->assertForbidden();

        $this->assertNull($otro->fresh()->assigned_to);
        $this->assertNull($libre->fresh()->assigned_to);
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

        $this->as('agApp')->post(route('tickets.department.update', $ticket), ['categoria' => $categoria->id])
            ->assertRedirect(route('tickets.show', $ticket));

        $ticket = $ticket->fresh();
        $this->assertSame(Department::WebApps, $ticket->department);
        $this->assertSame($categoria->id, $ticket->category_id);
        $evento = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::DepartmentChanged->value)->firstOrFail();
        $this->assertTrue($evento->visible_to_customer);
        $this->assertSame($this->a['agSup']->id, $ticket->assigned_to, 'la asignación se mantiene al cambiar a otro equipo (decidido)');
        $this->as('cuA')->get(route('tickets.show', $ticket))->assertOk()->assertSee('cambiado el departamento');
    }

    #[TestDox('R6: el departamento nuevo sale de la categoría (un `departamento` enviado aparte se ignora); categoría inactiva o del mismo departamento se rechazan')]
    public function test_department_comes_from_category(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $deWebs = $this->categoria(Department::Websites);
        $inactiva = Category::factory()->forDepartment(Department::WebApps)->create(['active' => false]);
        $dePropio = $this->categoria(Department::Support);

        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['categoria' => $inactiva->id])->assertSessionHasErrors('categoria');
        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['categoria' => $dePropio->id])->assertSessionHasErrors('categoria');
        $this->assertSame(Department::Support, $ticket->fresh()->department);

        // Un departamento incoherente enviado a mano no gana a la categoría.
        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['departamento' => 'web_apps', 'categoria' => $deWebs->id])->assertRedirect();
        $this->assertSame(Department::Websites, $ticket->fresh()->department);
        $this->assertSame($deWebs->id, $ticket->fresh()->category_id);
    }

    #[TestDox('Pasar un ticket a Contabilidad vacía la asignación (el asignado no la atiende), deja los dos eventos y el agente va a la bandeja (ya no lo ve, R13)')]
    public function test_moving_to_accounting_clears_assignment_and_hides_it_from_other_teams(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');
        $categoria = $this->categoria(Department::Other);

        $this->as('agSup')->post(route('tickets.department.update', $ticket), ['categoria' => $categoria->id])
            ->assertRedirect(route('inbox'));

        $this->assertNull($ticket->fresh()->assigned_to);
        $retirada = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', TicketEventType::Assigned->value)->firstOrFail();
        $this->assertSame('department_changed', $retirada->data['reason']);
        $this->assertFalse($retirada->visible_to_customer);
        $this->as('agSup')->get(route('tickets.show', $ticket))->assertForbidden();
        $this->as('agCon')->get(route('tickets.show', $ticket))->assertOk();
        $this->as('admin')->get(route('tickets.show', $ticket))->assertOk();
    }

    #[TestDox('Sacar un ticket de Contabilidad lo deja sin asignar si lo llevaba su agente, que ya no lo ve y va a la bandeja (R12, R13)')]
    public function test_moving_out_of_accounting_clears_assignment(): void
    {
        $conta = $this->ticket(Department::Other, TicketStatus::Open, 'agCon');
        $categoria = $this->categoria(Department::WebApps);

        $this->as('agCon')->post(route('tickets.department.update', $conta), ['categoria' => $categoria->id])->assertRedirect(route('inbox'));
        $this->assertSame(Department::WebApps, $conta->fresh()->department);
        $this->assertNull($conta->fresh()->assigned_to, 'el agente de Contabilidad no puede atender Aplicaciones');
        $this->as('agCon')->get(route('tickets.show', $conta))->assertForbidden();
    }

    #[TestDox('El administrador reclasifica cualquier ticket no final (también de y a Contabilidad): se queda en el ticket, queda su evento visible al cliente y, si el asignado no puede atenderlo, se vacía la asignación; en un ticket final, no (R12, decidido 2026-11-11)')]
    public function test_admin_reclassifies(): void
    {
        $normal = $this->ticket(Department::Support, TicketStatus::Open, 'agSup');
        $conta = $this->ticket(Department::Other, TicketStatus::Open, 'agCon');
        $final = $this->ticket(Department::Support, TicketStatus::Closed);
        $deWebs = $this->categoria(Department::WebApps);
        $deSoporte = $this->categoria(Department::Support);

        // Soporte → Aplicaciones: el asignado (Soporte) sí atiende Aplicaciones (cualquier agente de los dos equipos), se conserva.
        $this->as('admin')->post(route('tickets.department.update', $normal), ['categoria' => $deWebs->id])->assertRedirect(route('tickets.show', $normal));
        $this->assertSame(Department::WebApps, $normal->fresh()->department);
        $evento = TicketEvent::query()->where('ticket_id', $normal->id)->where('type', TicketEventType::DepartmentChanged->value)->firstOrFail();
        $this->assertSame($this->a['admin']->id, $evento->actor_id);
        $this->assertTrue($evento->visible_to_customer);

        // Contabilidad → Soporte: el agente de Contabilidad no puede atenderlo, se vacía; el administrador SIGUE viéndolo (no va a la bandeja).
        $this->as('admin')->post(route('tickets.department.update', $conta), ['categoria' => $deSoporte->id])->assertRedirect(route('tickets.show', $conta));
        $this->assertSame(Department::Support, $conta->fresh()->department);
        $this->assertNull($conta->fresh()->assigned_to);

        // Estado final: nadie reclasifica, tampoco él.
        $this->as('admin')->post(route('tickets.department.update', $final), ['categoria' => $deWebs->id])->assertForbidden();
        $this->assertSame(Department::Support, $final->fresh()->department);
    }

    #[TestDox('Los clientes no cambian el departamento (403) y en un ticket final nadie lo cambia')]
    public function test_clients_and_final_tickets_cannot_change_department(): void
    {
        $categoria = $this->categoria(Department::WebApps);
        $vivo = $this->ticket(Department::Support, TicketStatus::Open);
        $final = $this->ticket(Department::Support, TicketStatus::Resolved, 'agSup');

        $this->as('cuA')->post(route('tickets.department.update', $vivo), ['categoria' => $categoria->id])->assertForbidden();
        $this->as('agSup')->post(route('tickets.department.update', $final), ['categoria' => $categoria->id])->assertForbidden();
    }

    // ---------- lo que se ve ----------

    #[TestDox('El detalle del equipo ofrece solo los controles que la policy permite: el agente ajeno ve «Asignármelo» y no «Enviar al cliente»; el administrador, solo reasignar, mover de departamento y prioridad')]
    public function test_detail_offers_controls_by_policy(): void
    {
        $ticket = $this->ticket(Department::Support, TicketStatus::InProgress, 'agSup');

        $this->as('agSup')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Enviar al cliente')->assertSee('Guardar nota')->assertSee('Cambiar estado')->assertSee('Reasignar')->assertSee('Mover ticket')->assertDontSee('Asignármelo');
        $this->as('agApp')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Asignármelo')->assertSee('Guardar nota')->assertDontSee('Enviar al cliente')->assertDontSee('Cambiar estado');
        $this->as('admin')->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Reasignar')->assertSee('Mover ticket')->assertSee('Cambiar prioridad')->assertDontSee('Enviar al cliente')->assertDontSee('Guardar nota')->assertDontSee('Cambiar estado');
    }
}
