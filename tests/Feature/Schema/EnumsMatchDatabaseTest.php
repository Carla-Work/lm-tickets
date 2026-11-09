<?php

/*
 * Comprueba que cada valor de cada enumeración está permitido por las restricciones CHECK de la base
 * de datos. Qué evita: añadir un caso a una enumeración y olvidar la migración, lo que haría que
 * el fallo apareciese en producción al usar ese valor.
 * Cuándo tocarlo: al añadir una enumeración que se guarde en la base de datos.
 *
 * Si este test se pone rojo tras añadir un caso, NO se toca el test: se amplía el CHECK con una
 * migración nueva.
 */

namespace Tests\Feature\Schema;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\Team;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class EnumsMatchDatabaseTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Todos los departamentos están permitidos en tickets y en categorías')]
    public function test_every_department_is_accepted(): void
    {
        foreach (Department::cases() as $departamento) {
            Category::factory()->forDepartment($departamento)->create();
            Ticket::factory()->forDepartment($departamento)->create();
        }

        $this->assertSame(count(Department::cases()), Ticket::count());
    }

    #[TestDox('Todos los estados de ticket están permitidos')]
    public function test_every_status_is_accepted(): void
    {
        foreach (TicketStatus::cases() as $estado) {
            Ticket::factory()->status($estado)->create();
        }

        $this->assertSame(count(TicketStatus::cases()), Ticket::count());
    }

    #[TestDox('Todos los tipos de mensaje están permitidos')]
    public function test_every_message_kind_is_accepted(): void
    {
        $ticket = Ticket::factory()->create();
        foreach (MessageKind::cases() as $tipo) {
            TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'author_id' => $ticket->requester_id, 'kind' => $tipo->value]);
        }

        $this->assertSame(count(MessageKind::cases()), TicketMessage::count());
    }

    #[TestDox('Todos los roles y equipos están permitidos (cada uno con su combinación válida)')]
    public function test_every_role_and_team_is_accepted(): void
    {
        User::factory()->create();               // company_user
        User::factory()->companyAdmin()->create();
        User::factory()->admin()->create();
        foreach (Team::cases() as $equipo) {
            User::factory()->agent($equipo)->create();
        }

        $roles = User::pluck('role')->map(fn ($r) => $r->value)->unique()->sort()->values()->all();
        $esperados = collect(UserRole::cases())->map(fn ($r) => $r->value)->sort()->values()->all();

        $this->assertSame($esperados, $roles, 'hay un rol sin probar: añadir su caso a este test');
    }
}
