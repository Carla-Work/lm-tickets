<?php

/*
 * Valorar la atención de un ticket (P1, R5). Cuándo tocarla: al cambiar quién, cuándo o qué se guarda.
 *
 * Trampas:
 *  - Una sola vez: la segunda es un error, NO una sustitución. Se comprueba con el ticket bloqueado y, además, la clave
 *    única de `ticket_ratings.ticket_id` lo impide en la base si dos envíos llegan a la vez.
 *  - NO es un mensaje: no escribe en el hilo, no cambia el estado y no toca `last_activity_at` (reordenaría la lista).
 *    No avisa a nadie (decidido; se podría avisar al agente si la nota es ≤ 2).
 *  - Dos capas de permiso: el controlador y aquí, con el ticket releído y bloqueado. El permiso (`rate`) es del solicitante y
 *    solo con el ticket en estado final.
 *  - El evento es visible al cliente («Valoraste la atención»); el comentario NO va en el evento (el historial no guarda texto libre).
 */

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RateTicket
{
    public function __invoke(User $actor, Ticket $ticket, int $score, ?string $comment): TicketRating
    {
        return DB::transaction(function () use ($actor, $ticket, $score, $comment): TicketRating {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('rate', $ticket);

            if (TicketRating::query()->where('ticket_id', $ticket->id)->exists()) {
                throw ValidationException::withMessages(['puntuacion' => 'Este ticket ya está valorado. La valoración no se puede cambiar.']);
            }
            if ($score < 1 || $score > 5) {
                throw ValidationException::withMessages(['puntuacion' => 'La puntuación va de 1 a 5.']);
            }

            $rating = new TicketRating;
            $rating->ticket_id = $ticket->id;
            $rating->rated_by = $actor->id;
            $rating->score = $score;
            $rating->comment = $comment === null || trim($comment) === '' ? null : trim($comment);
            $rating->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::Rated;
            $event->data = ['score' => $score];
            $event->visible_to_customer = true;
            $event->save();

            return $rating;
        });
    }
}
