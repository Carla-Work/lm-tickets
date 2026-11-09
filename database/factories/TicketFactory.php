<?php

/*
 * Datos de prueba de tickets. Cuándo tocarlo: al añadir datos a Ticket.
 *
 * Crea un ticket COHERENTE: el solicitante pertenece a la empresa del ticket, y el departamento
 * es el de la categoría. No crea mensajes: el primer mensaje (la descripción) lo añade quien lo
 * necesite. El número lo asigna el modelo (TicketSequence).
 */

namespace Database\Factories;

use App\Enums\Department;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'requester_id' => fn (array $atributos) => User::factory()->forCompany(Company::findOrFail($atributos['company_id']))->create()->id,
            'department' => Department::Support->value,
            'category_id' => fn (array $atributos) => Category::factory()->forDepartment(Department::from($atributos['department']))->create()->id,
            'subject' => fake()->sentence(5),
            'status' => TicketStatus::Open->value,
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(fn () => ['department' => $department->value]);
    }
}
