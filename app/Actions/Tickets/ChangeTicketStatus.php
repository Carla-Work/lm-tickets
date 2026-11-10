<?php

/*
 * Cambiar el estado de un ticket (R14, R15, R16), también el paso a «Resuelto» y a «Cerrado».
 * Cuándo tocarla: al cambiar las transiciones permitidas (TicketStatus::allowedTargets) o qué se guarda al resolver.
 *
 * Trampas:
 *  - Quién puede hacerlo lo decide la policy en el controlador; AQUÍ se vuelve a comprobar con el ticket
 *    releído y bloqueado, porque dos personas pueden actuar a la vez (p. ej. el cliente marca «resuelto»
 *    mientras el agente lo cierra).
 *  - R15: nadie reabre. `allowedTargets()` no ofrece ninguna salida de Resuelto salvo Cerrado, y de Cerrado
 *    ninguna. No se salta desde aquí «porque es un caso especial».
 *  - R16: pasar a Resuelto EXIGE explicar cómo; se guarda en `tickets.resolution`.
 *  - Cada cambio deja su evento (R5). Los de estado son visibles al cliente; el texto de la solución también.
 */

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeTicketStatus
{
    /**
     * @param  string  $ability  habilidad de TicketPolicy que autoriza ESTE cambio (markResolved, resolve, close o updateStatus)
     */
    public function __invoke(User $actor, Ticket $ticket, TicketStatus $to, string $ability, ?string $resolution = null): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $to, $ability, $resolution): Ticket {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize($ability, $ticket);

            $from = $ticket->status;
            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['estado' => "No se puede pasar un ticket de «{$from->label()}» a «{$to->label()}»."]);
            }

            $resolution = $resolution === null ? null : trim($resolution);
            if ($to === TicketStatus::Resolved && ($resolution === null || $resolution === '')) {
                throw ValidationException::withMessages(['solucion' => 'Explica cómo se ha resuelto antes de marcarlo como resuelto.']);
            }

            $ticket->status = $to;
            if ($to === TicketStatus::Resolved) {
                $ticket->resolution = $resolution;
                $ticket->resolved_at = now();
            }
            if ($to === TicketStatus::Closed) {
                $ticket->closed_at = now();
            }
            $ticket->last_activity_at = now();
            $ticket->save();

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = match ($to) {
                TicketStatus::Resolved => TicketEventType::Resolved,
                TicketStatus::Closed => TicketEventType::Closed,
                default => TicketEventType::StatusChanged,
            };
            $event->data = ['from' => $from->value, 'to' => $to->value]
                + ($to === TicketStatus::Resolved ? ['resolution' => $resolution] : []);
            $event->visible_to_customer = true;
            $event->save();

            return $ticket;
        });
    }
}
