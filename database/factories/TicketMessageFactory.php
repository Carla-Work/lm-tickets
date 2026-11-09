<?php

/*
 * Datos de prueba de mensajes del hilo. Cuándo tocarlo: al añadir datos a TicketMessage.
 * Por defecto es un mensaje PÚBLICO; las notas internas se piden con `internal()`.
 */

namespace Database\Factories;

use App\Enums\MessageKind;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => User::factory(),
            'kind' => MessageKind::Public->value,
            'body' => fake()->paragraph(),
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['kind' => MessageKind::Internal->value]);
    }
}
