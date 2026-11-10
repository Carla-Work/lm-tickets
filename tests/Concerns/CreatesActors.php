<?php

/*
 * Reparto de personas para los tests de autorización: dos empresas, clientes de cada una, agentes de los
 * dos equipos y el administrador. Cuándo tocarlo: al añadir un rol o un tipo de actor.
 *
 *   Empresa A: cuA (usuario, el que abre los tickets de prueba), cuA2 (otro usuario), caA (administrador de empresa)
 *   Empresa B: cuB (usuario), caB (administrador de empresa)
 *   Equipo:    agSup (agente de Soporte), agApp (agente de Desarrollo y Diseño), admin (administrador)
 */

namespace Tests\Concerns;

use App\Enums\Department;
use App\Enums\Team;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;

trait CreatesActors
{
    /** @var array<string, User> */
    protected array $a = [];

    protected Company $empresaA;

    protected Company $empresaB;

    protected function crearActores(): void
    {
        $this->empresaA = Company::factory()->create(['name' => 'Empresa A']);
        $this->empresaB = Company::factory()->create(['name' => 'Empresa B']);

        $this->a = [
            'cuA' => User::factory()->forCompany($this->empresaA)->create(['name' => 'Usuario A']),
            'cuA2' => User::factory()->forCompany($this->empresaA)->create(['name' => 'Usuario A2']),
            'caA' => User::factory()->companyAdmin()->forCompany($this->empresaA)->create(['name' => 'Admin empresa A']),
            'cuB' => User::factory()->forCompany($this->empresaB)->create(['name' => 'Usuario B']),
            'caB' => User::factory()->companyAdmin()->forCompany($this->empresaB)->create(['name' => 'Admin empresa B']),
            'agSup' => User::factory()->agent(Team::Support)->create(['name' => 'Agente Soporte']),
            'agApp' => User::factory()->agent(Team::Applications)->create(['name' => 'Agente Aplicaciones']),
            'admin' => User::factory()->admin()->create(['name' => 'Administrador']),
        ];
    }

    /** Un ticket de la empresa A abierto por cuA. */
    protected function ticket(Department $department = Department::WebApps, TicketStatus $status = TicketStatus::Open, ?string $assignedTo = null): Ticket
    {
        return Ticket::factory()->create([
            'company_id' => $this->empresaA->id,
            'requester_id' => $this->a['cuA']->id,
            'department' => $department->value,
            'status' => $status->value,
            'assigned_to' => $assignedTo === null ? null : $this->a[$assignedTo]->id,
        ]);
    }
}
