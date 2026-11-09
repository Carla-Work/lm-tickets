<?php

/*
 * Quién VE qué tickets. Reglas R1 (nunca los de otra empresa), R11 (el agente ve todo) y R13 («Otros»
 * solo lo ven el administrador y el cliente).
 * Cuándo tocarlo: solo si la usuaria cambia quién ve qué. Si falla un test, se arregla el código o se
 * discute la regla; NO se adapta el test.
 *
 * Lo esencial de este fichero: el ámbito `visibleTo` (que filtra listas, búsquedas y contadores) y la
 * policy `view` (que responde por un ticket concreto) DEBEN dar siempre lo mismo. Si divergen, hay una
 * pantalla que enseña lo que otra niega.
 */

namespace Tests\Feature\Authorization;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CreatesActors;
use Tests\TestCase;

class TicketVisibilityTest extends TestCase
{
    use CreatesActors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearActores();
    }

    /** @return list<string> los actores que ven un ticket, por orden alfabético */
    private function quienVe(Ticket $ticket): array
    {
        $vistos = [];
        foreach ($this->a as $clave => $actor) {
            if ($actor->can('view', $ticket)) {
                $vistos[] = $clave;
            }
        }
        sort($vistos);

        return $vistos;
    }

    #[TestDox('Un ticket normal lo ven: su autor, el administrador de su empresa, los agentes de los DOS equipos y el administrador (R1, R11)')]
    public function test_who_sees_a_normal_ticket(): void
    {
        $this->assertSame(['admin', 'agApp', 'agSup', 'caA', 'cuA'], $this->quienVe($this->ticket(Department::WebApps)));
    }

    #[TestDox('Un ticket de «Otros» lo ven SOLO su autor, el administrador de su empresa y el administrador: ningún agente (R13)')]
    public function test_who_sees_an_other_ticket(): void
    {
        $this->assertSame(['admin', 'caA', 'cuA'], $this->quienVe($this->ticket(Department::Other)));
    }

    #[TestDox('Un compañero de la misma empresa NO ve el ticket de otro usuario (solo el administrador de empresa lo ve)')]
    public function test_company_colleague_does_not_see_others_tickets(): void
    {
        $ticket = $this->ticket();

        $this->assertFalse($this->a['cuA2']->can('view', $ticket));
        $this->assertTrue($this->a['caA']->can('view', $ticket));
    }

    #[TestDox('NADIE de otra empresa ve un ticket ajeno, tampoco su administrador de empresa (R1)')]
    public function test_other_company_never_sees_the_ticket(): void
    {
        foreach ([Department::Support, Department::WebApps, Department::Websites, Department::Other] as $departamento) {
            $ticket = $this->ticket($departamento);

            $this->assertFalse($this->a['cuB']->can('view', $ticket), "cuB no debe ver un ticket de $departamento->value");
            $this->assertFalse($this->a['caB']->can('view', $ticket), "caB no debe ver un ticket de $departamento->value");
        }
    }

    #[TestDox('Un agente que ve un ticket de otro equipo también lo ve: Soporte ve el de Aplicaciones y al revés (R11)')]
    public function test_agents_see_both_teams(): void
    {
        $deSoporte = $this->ticket(Department::Support);
        $deApps = $this->ticket(Department::WebApps);

        foreach (['agSup', 'agApp'] as $agente) {
            $this->assertTrue($this->a[$agente]->can('view', $deSoporte));
            $this->assertTrue($this->a[$agente]->can('view', $deApps));
        }
    }

    #[TestDox('Cambiar un ticket a «Otros» hace que los agentes dejen de verlo (R13)')]
    public function test_moving_to_other_hides_it_from_agents(): void
    {
        $ticket = $this->ticket(Department::WebApps);
        $this->assertTrue($this->a['agApp']->can('view', $ticket));

        $ticket->forceFill(['department' => Department::Other->value])->save();

        $this->assertFalse($this->a['agApp']->fresh()->can('view', $ticket->fresh()));
        $this->assertFalse($this->a['agSup']->fresh()->can('view', $ticket->fresh()));
        $this->assertTrue($this->a['admin']->can('view', $ticket->fresh()));
    }

    #[TestDox('El ámbito visibleTo y la policy view dan EXACTAMENTE lo mismo en todas las combinaciones de rol, departamento y estado')]
    public function test_scope_and_policy_agree_everywhere(): void
    {
        $tickets = [];
        foreach (Department::cases() as $departamento) {
            foreach (TicketStatus::cases() as $estado) {
                $tickets[] = $this->ticket($departamento, $estado);
            }
        }
        // y tickets de la empresa B y de otro autor de la A
        $tickets[] = Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id]);
        $tickets[] = Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA2']->id]);

        foreach ($this->a as $clave => $actor) {
            $porAmbito = Ticket::visibleTo($actor)->pluck('id')->sort()->values()->all();
            $porPolicy = collect($tickets)->filter(fn (Ticket $t) => $actor->can('view', $t))->pluck('id')->sort()->values()->all();

            $this->assertSame($porPolicy, $porAmbito, "el ámbito y la policy divergen para $clave");
        }
    }

    #[TestDox('Las listas por ámbito dan el recuento esperado para cada rol')]
    public function test_visible_counts(): void
    {
        $this->ticket(Department::Support);                  // 1 de A (de cuA)
        $this->ticket(Department::WebApps);                  // 2 de A (de cuA)
        $this->ticket(Department::Other);                    // 3 de A (de cuA), «Otros»
        Ticket::factory()->create(['company_id' => $this->empresaA->id, 'requester_id' => $this->a['cuA2']->id, 'department' => 'support']); // 4: de cuA2
        Ticket::factory()->create(['company_id' => $this->empresaB->id, 'requester_id' => $this->a['cuB']->id, 'department' => 'websites']); // 5: de B

        $this->assertSame(3, Ticket::visibleTo($this->a['cuA'])->count(), 'cuA: solo los suyos');
        $this->assertSame(1, Ticket::visibleTo($this->a['cuA2'])->count(), 'cuA2: solo el suyo');
        $this->assertSame(4, Ticket::visibleTo($this->a['caA'])->count(), 'caA: los 4 de su empresa');
        $this->assertSame(1, Ticket::visibleTo($this->a['cuB'])->count(), 'cuB: solo el suyo');
        $this->assertSame(1, Ticket::visibleTo($this->a['caB'])->count(), 'caB: el único de su empresa');
        $this->assertSame(4, Ticket::visibleTo($this->a['agSup'])->count(), 'agente: 5 menos el de «Otros»');
        $this->assertSame(5, Ticket::visibleTo($this->a['admin'])->count(), 'administrador: todos');
    }

    #[TestDox('Un filtro añadido por encima del ámbito no puede ampliarlo: buscar por empresa ajena no devuelve nada')]
    public function test_extra_filters_cannot_widen_the_scope(): void
    {
        $this->ticket();

        $this->assertSame(0, Ticket::visibleTo($this->a['cuB'])->where('company_id', $this->empresaA->id)->count());
        $this->assertSame(0, Ticket::visibleTo($this->a['caB'])->where('company_id', $this->empresaA->id)->count());
        $this->assertSame(0, Ticket::visibleTo($this->a['cuA2'])->where('requester_id', $this->a['cuA']->id)->count());
        $this->assertSame(0, Ticket::visibleTo($this->a['agApp'])->where('department', 'other')->count());
    }

    #[TestDox('Abrir tickets: solo los clientes pueden; empleados y administrador no (los abre el cliente)')]
    public function test_only_clients_can_create_tickets(): void
    {
        foreach (['cuA', 'cuA2', 'caA', 'cuB', 'caB'] as $cliente) {
            $this->assertTrue($this->a[$cliente]->can('create', Ticket::class), "$cliente debería poder abrir tickets");
        }
        foreach (['agSup', 'agApp', 'admin'] as $empleado) {
            $this->assertFalse($this->a[$empleado]->can('create', Ticket::class), "$empleado no debería poder abrir tickets");
        }
    }
}
