<?php

/*
 * Cambiar la prioridad INTERNA de un ticket (P1, R5). Cuándo tocarla: al cambiar quién puede o qué se registra.
 *
 * Trampas:
 *  - NO toca `last_activity_at`: la prioridad es interna, y mover esa fecha reordenaría la lista del cliente (delatando
 *    que el equipo hizo algo) y falsearía «sin actividad desde hace N días» en la bandeja.
 *  - El evento es INTERNO (`visible_to_customer` = false): el cliente nunca se entera, ni por el historial ni por avisos.
 *    No avisa a nadie (no llama a TicketNotifier).
 *  - Dos capas de permiso: el controlador autoriza con la policy y aquí se vuelve a autorizar con el ticket releído y
 *    bloqueado (un agente lo resuelve mientras otro cambia la prioridad).
 */

namespace App\Actions\Tickets;

use App\Enums\Priority;
use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangePriority
{
    public function __invoke(User $actor, Ticket $ticket, Priority $to): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $to): Ticket {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('changePriority', $ticket);

            $from = $ticket->priority;
            if ($from === $to) {
                throw ValidationException::withMessages(['prioridad' => "El ticket ya tiene la prioridad «{$to->label()}»."]);
            }

            $ticket->priority = $to;
            $ticket->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::PriorityChanged;
            $event->data = ['from' => $from->value, 'to' => $to->value];
            $event->visible_to_customer = false;
            $event->save();

            return $ticket;
        });
    }
}
