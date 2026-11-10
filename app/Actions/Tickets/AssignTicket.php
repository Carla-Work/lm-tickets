<?php

/*
 * Asignar, reasignar o dejar sin asignar un ticket (R11, R5).
 * Cuándo tocarla: al cambiar quién puede recibir un ticket o qué se registra al reasignar.
 *
 * Trampas:
 *  - Solo se puede asignar a un AGENTE operativo (no a un cliente, ni a una cuenta de baja, ni al administrador:
 *    el administrador no responde fuera de «Otros» (R10) y un ticket asignado a quien no puede atenderlo
 *    se quedaría sin dueño). El cambio de una persona a otra del OTRO equipo es válido (R11).
 *  - Quién puede asignar lo decide la policy `assign` (bloquea estados finales y «Otros»); se recomprueba
 *    aquí con el ticket releído y bloqueado.
 *  - El evento guarda los NOMBRES además de los ids: el historial debe seguir diciendo lo que pasó aunque
 *    la persona cambie de nombre. Es interno (no lo ve el cliente).
 */

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignTicket
{
    public function __construct(private readonly TicketNotifier $notifier) {}

    public function __invoke(User $actor, Ticket $ticket, ?User $to): Ticket
    {
        $ticket = DB::transaction(function () use ($actor, $ticket, $to): Ticket {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('assign', $ticket);

            if ($to !== null && ! ($to->hasRole(UserRole::Agent) && $to->isOperational())) {
                throw ValidationException::withMessages(['asignado' => 'Solo se puede asignar a un agente en activo.']);
            }
            if ($ticket->assigned_to === $to?->id) {
                throw ValidationException::withMessages(['asignado' => 'El ticket ya está así asignado.']);
            }

            $from = $ticket->assigned_to === null ? null : User::find($ticket->assigned_to);
            $ticket->assigned_to = $to?->id;
            $ticket->last_activity_at = now();
            $ticket->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::Assigned;
            $event->data = ['from' => $from?->id, 'from_name' => $from?->name, 'to' => $to?->id, 'to_name' => $to?->name];
            $event->visible_to_customer = false; // las reasignaciones internas no le conciernen al cliente (R5, mockup)
            $event->save();

            return $ticket;
        });

        $this->notifier->assigned($ticket, $actor, $to); // después de la transacción

        return $ticket;
    }
}
