<?php

/*
 * Bandeja del equipo y detalle interno. Reglas: R13 (el agente no ve «Otros» por NINGUNA vía: ni la lista,
 * ni los recuentos, ni el selector de empresas), R10/R11 (el administrador lo ve todo), R2 (la nota interna
 * se pinta como nota para el equipo y no llega al cliente), cuentas de baja sin acceso.
 * Cuándo tocarlo: al cambiar la bandeja o el detalle interno. NO se adapta un test para que pase.
 */

namespace Tests\Feature\Tickets;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** Ticket de la empresa A (o de otra) con asunto reconocible. */
    private function conAsunto(string $asunto, Department $dep = Department::WebApps, TicketStatus $estado = TicketStatus::Open, ?string $asignadoA = null, ?Company $empresa = null): Ticket
    {
        $empresa ??= $this->empresaA;
        $solicitante = $empresa->is($this->empresaA) ? $this->a['cuA'] : $this->a['cuB'];

        return Ticket::factory()->create([
            'company_id' => $empresa->id, 'requester_id' => $solicitante->id, 'subject' => $asunto,
            'department' => $dep->value, 'status' => $estado->value,
            'assigned_to' => $asignadoA === null ? null : $this->a[$asignadoA]->id,
        ]);
    }

    #[TestDox('Un cliente no entra en la bandeja (403) y sin sesión se le manda a acceder')]
    public function test_clients_cannot_open_inbox(): void
    {
        foreach (['cuA', 'caA'] as $cliente) {
            $this->actingAs($this->a[$cliente])->get(route('inbox'))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('inbox'))->assertRedirect(route('login'));
    }

    #[TestDox('Una cuenta de baja del equipo no entra en la bandeja')]
    public function test_deactivated_staff_cannot_open_inbox(): void
    {
        $baja = User::factory()->agent()->create(['deactivated_at' => now()]);

        $this->actingAs($baja)->get(route('inbox'))->assertRedirect(route('login'));
    }

    #[TestDox('R13: un agente de Soporte o de Desarrollo y Diseño NO ve los tickets de Contabilidad en la bandeja; el administrador sí')]
    public function test_agent_does_not_see_other_department_admin_does(): void
    {
        $this->conAsunto('Ticket-normal-visible');
        $this->conAsunto('Ticket-otros-secreto', Department::Other);

        $this->actingAs($this->a['agSup'])->get(route('inbox'))->assertOk()
            ->assertSee('Ticket-normal-visible')->assertDontSee('Ticket-otros-secreto');
        $this->actingAs($this->a['admin'])->get(route('inbox'))->assertOk()
            ->assertSee('Ticket-normal-visible')->assertSee('Ticket-otros-secreto');
    }

    #[TestDox('R13: ni los recuentos, ni el selector de empresas, ni el de departamentos delatan Contabilidad a un agente de otro equipo')]
    public function test_agent_counters_and_filters_do_not_leak_other(): void
    {
        // La empresa B solo tiene tickets de «Otros»: un agente no debe enterarse de que tiene tickets.
        $this->conAsunto('Normal de A');
        $this->conAsunto('Otros de B', Department::Other, TicketStatus::Open, null, $this->empresaB);

        $respuesta = $this->actingAs($this->a['agSup'])->get(route('inbox'))->assertOk();
        $respuesta->assertSee('Empresa A')->assertDontSee('Empresa B');
        $respuesta->assertSee('1 de 1 tickets');            // el total no cuenta «Otros»
        $this->assertSame(1, $respuesta->viewData('summary')['unassigned']);
        $this->assertNotContains(Department::Other, $respuesta->viewData('departments'));

        $admin = $this->actingAs($this->a['admin'])->get(route('inbox'))->assertOk();
        $admin->assertSee('Empresa B')->assertSee('2 de 2 tickets');
        $this->assertContains(Department::Other, $admin->viewData('departments'));
    }

    #[TestDox('R11/R13: el agente de Contabilidad ve SOLO Contabilidad (lista, recuentos, filtros y detalle) y no puede abrir los de otros departamentos')]
    public function test_accounting_agent_sees_only_accounting(): void
    {
        $this->conAsunto('Factura-de-conta', Department::Other);
        $soporte = $this->conAsunto('Soporte-oculto', Department::Support);

        $respuesta = $this->actingAs($this->a['agCon'])->get(route('inbox'))->assertOk()
            ->assertSee('Factura-de-conta')->assertDontSee('Soporte-oculto')->assertSee('1 de 1 tickets');
        $this->assertSame([Department::Other], array_values($respuesta->viewData('departments')));

        $this->actingAs($this->a['agCon'])->get(route('inbox', ['departamento' => 'support']))->assertOk()->assertSee('0 de 1 tickets')->assertDontSee('Soporte-oculto');
        $this->actingAs($this->a['agCon'])->get(route('tickets.show', $soporte))->assertForbidden();
    }

    #[TestDox('En el detalle solo se ofrecen para asignar los agentes que pueden atender ese departamento: en Contabilidad solo su equipo; en los demás, no el de Contabilidad')]
    public function test_assign_list_offers_only_eligible_agents(): void
    {
        $conta = $this->conAsunto('Lista-conta', Department::Other);
        $soporte = $this->conAsunto('Lista-soporte', Department::Support);

        $this->actingAs($this->a['admin'])->get(route('tickets.show', $conta))->assertOk()
            ->assertSee('Agente Contabilidad')->assertDontSee('Agente Soporte')->assertDontSee('Agente Aplicaciones');
        $this->actingAs($this->a['agSup'])->get(route('tickets.show', $soporte))->assertOk()
            ->assertSee('Agente Soporte')->assertSee('Agente Aplicaciones')->assertDontSee('Agente Contabilidad');
    }

    #[TestDox('Un agente de otro equipo que filtra por Contabilidad a mano no obtiene nada')]
    public function test_agent_filtering_by_other_gets_nothing(): void
    {
        $this->conAsunto('Otros-escondido', Department::Other);

        $this->actingAs($this->a['agSup'])->get(route('inbox', ['departamento' => 'other']))
            ->assertOk()->assertDontSee('Otros-escondido')->assertSee('0 de 0 tickets');
    }

    #[TestDox('El resumen cuenta «Sin asignar» solo entre los tickets vivos y respeta el reparto por estado')]
    public function test_summary_counts(): void
    {
        $this->conAsunto('abierto sin asignar');
        $this->conAsunto('abierto asignado', Department::WebApps, TicketStatus::Open, 'agApp');
        $this->conAsunto('en curso', Department::Support, TicketStatus::InProgress, 'agSup');
        $this->conAsunto('pendiente sin asignar', Department::Support, TicketStatus::PendingCustomer);
        $this->conAsunto('resuelto sin asignar', Department::Support, TicketStatus::Resolved);
        $this->conAsunto('cerrado', Department::Support, TicketStatus::Closed, 'agSup');

        $resumen = $this->actingAs($this->a['agSup'])->get(route('inbox'))->viewData('summary');

        $this->assertSame(
            ['unassigned' => 2, 'open' => 2, 'in_progress' => 1, 'pending' => 1, 'finished' => 2],
            $resumen,
        );
    }

    #[TestDox('Los filtros de estado, asignado, empresa y buscador acotan la lista')]
    public function test_filters(): void
    {
        $this->conAsunto('Uno-abierto-sinasignar');
        $this->conAsunto('Dos-encurso-agsup', Department::Support, TicketStatus::InProgress, 'agSup');
        $this->conAsunto('Tres-deB', Department::Support, TicketStatus::Open, null, $this->empresaB);
        $agente = $this->actingAs($this->a['agSup']);

        $agente->get(route('inbox', ['estado' => 'in_progress']))->assertSee('Dos-encurso-agsup')->assertDontSee('Uno-abierto-sinasignar');
        $agente->get(route('inbox', ['asignado' => 'sin']))->assertSee('Uno-abierto-sinasignar')->assertSee('Tres-deB')->assertDontSee('Dos-encurso-agsup');
        $agente->get(route('inbox', ['asignado' => $this->a['agSup']->id]))->assertSee('Dos-encurso-agsup')->assertDontSee('Uno-abierto-sinasignar');
        $agente->get(route('inbox', ['empresa' => $this->empresaB->id]))->assertSee('Tres-deB')->assertDontSee('Uno-abierto-sinasignar');
        // El buscador mira también a la persona y a la empresa, no solo el asunto.
        $agente->get(route('inbox', ['q' => 'Usuario B']))->assertSee('Tres-deB')->assertDontSee('Uno-abierto-sinasignar');
        $agente->get(route('inbox', ['q' => 'Empresa A']))->assertSee('Uno-abierto-sinasignar')->assertDontSee('Tres-deB');
    }

    #[TestDox('Los filtros no admiten valores inventados (se rechazan, no rompen la consulta)')]
    public function test_invalid_filters_are_rejected(): void
    {
        $agente = $this->actingAs($this->a['agSup']);

        $agente->get(route('inbox', ['estado' => 'inventado']))->assertSessionHasErrors('estado');
        $agente->get(route('inbox', ['asignado' => '1 OR 1=1']))->assertSessionHasErrors('asignado');
    }

    #[TestDox('El detalle interno pinta la nota como NOTA INTERNA para el equipo; el cliente nunca la ve (R2)')]
    public function test_internal_note_is_shown_to_staff_not_to_client(): void
    {
        $ticket = $this->conAsunto('Con nota', Department::Support, TicketStatus::InProgress, 'agSup');
        TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agSup']->id, 'body' => 'NOTA-SOLO-EQUIPO']);
        TicketMessage::factory()->internal()->create(['ticket_id' => $ticket->id, 'author_id' => $this->a['agSup']->id, 'body' => 'NOTA-SECRETA-123']);

        $equipo = $this->actingAs($this->a['agApp'])->get(route('tickets.show', $ticket))->assertOk();
        $equipo->assertSee('NOTA-SECRETA-123')->assertSee('Nota interna')->assertSee('Solo equipo de Ling Marco')
            ->assertSee('Bandeja'); // vista del equipo, no la del cliente

        $this->actingAs($this->a['cuA'])->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('NOTA-SOLO-EQUIPO')->assertDontSee('NOTA-SECRETA-123')->assertDontSee('Nota interna');
    }

    #[TestDox('El menú del equipo ofrece SU «Nuevo ticket» (para un cliente, P1 2026-11-12) pero no el del cliente ni enlaces a pantallas que no existen')]
    public function test_staff_menu_has_no_client_actions(): void
    {
        // Revisada el 2026-11-12: antes decía «el equipo no ofrece Nuevo ticket (solo el cliente abre tickets)». La usuaria decidió que
        // agentes y administrador creen tickets a nombre de un cliente; lo que sigue prohibido es la entrada del CLIENTE (/tickets/nuevo).
        $this->actingAs($this->a['admin'])->get(route('inbox'))->assertOk()
            ->assertSee('Panel interno')
            ->assertSee('href="'.route('tickets.staff.create').'"', false)
            ->assertDontSee('href="'.route('tickets.create').'"', false)
            // «Mis tickets» ya puede aparecer como VISTA RÁPIDA de la bandeja (mockup P1 validado); lo que el equipo no
            // debe tener es la entrada de menú del cliente, que lleva a /tickets (excepción documentada en la bitácora).
            ->assertDontSee('href="'.route('tickets.index').'"', false);
    }

    #[TestDox('El detalle de un ticket de Contabilidad sigue siendo 403 para un agente de otro equipo aunque llegue desde la bandeja (R13)')]
    public function test_agent_cannot_open_other_ticket_detail(): void
    {
        $ticket = $this->conAsunto('Otros-detalle', Department::Other);

        $this->actingAs($this->a['agSup'])->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($this->a['admin'])->get(route('tickets.show', $ticket))->assertOk();
    }
}
