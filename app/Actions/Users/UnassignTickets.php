<?php

/*
 * Quita a una persona los tickets que lleva y no están cerrados, dejando constancia (R5) en cada uno.
 * Cuándo tocarlo: si cambia qué ocurre con los tickets de quien deja de poder llevarlos.
 *
 * Por qué existe: un agente de baja, o el agente que pasa a administrador (que no responde fuera de «Otros»,
 * R10), seguiría figurando como responsable de tickets que ya no puede atender; quedarían huérfanos de verdad
 * (nadie los ve en «Sin asignar»). Decisión de Claude (2026-11-10), pendiente de confirmar por la usuaria.
 *
 * Trampas:
 *  - Se llama DENTRO de la transacción del cambio de cuenta; no abre la suya.
 *  - Los «Cerrados» se dejan como están: no admiten acciones y conservan quién los llevó.
 *  - El evento es interno (no lo ve el cliente) y no avisa a nadie: es una consecuencia, no una asignación.
 */

namespace App\Actions\Users;

use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;

class UnassignTickets
{
    /** @return int cuántos tickets se han liberado */
    public function __invoke(User $actor, User $from, string $reason): int
    {
        $tickets = Ticket::query()->where('assigned_to', $from->id)
            ->where('status', '!=', TicketStatus::Closed->value)->lockForUpdate()->get();

        foreach ($tickets as $ticket) {
            $ticket->assigned_to = null;
            $ticket->last_activity_at = now();
            $ticket->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::Assigned;
            $event->data = ['from' => $from->id, 'from_name' => $from->name, 'to' => null, 'to_name' => null, 'reason' => $reason];
            $event->visible_to_customer = false;
            $event->save();
        }

        return $tickets->count();
    }
}
