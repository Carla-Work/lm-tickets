<?php

/*
 * Añadir un mensaje al hilo: respuesta pública (la ve el cliente) o nota interna (solo el equipo, R2).
 * Cuándo tocarla: al cambiar qué ocurre al escribir en un ticket (adjuntos, 4d; avisos, paso 5).
 *
 * Trampas:
 *  - Los permisos se COMPRUEBAN OTRA VEZ aquí, con el ticket releído y bloqueado (lockForUpdate): entre que el
 *    controlador autorizó y esta transacción puede haber pasado que un agente resuelva el ticket. Sin esto, un
 *    cliente colaría un mensaje en un ticket ya resuelto (R4).
 *  - R14: si responde el CLIENTE y el ticket estaba «Pendiente de cliente», pasa solo a «En curso» y queda
 *    el evento (R5). Si responde el equipo el estado NO cambia solo (decidido): el agente lo elige a mano.
 *  - Mensaje + evento + última actividad van en UNA transacción: un mensaje sin su rastro rompe la lista.
 */

namespace App\Actions\Tickets;

use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReplyToTicket
{
    public function __invoke(User $author, Ticket $ticket, string $body, MessageKind $kind = MessageKind::Public): TicketMessage
    {
        return DB::transaction(function () use ($author, $ticket, $body, $kind): TicketMessage {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($author)->authorize($kind === MessageKind::Internal ? 'addNote' : 'reply', $ticket);

            $message = new TicketMessage(['body' => $body]);
            $message->ticket_id = $ticket->id;
            $message->author_id = $author->id;
            $message->kind = $kind;
            $message->save();

            // R14: la respuesta del cliente devuelve el balón al equipo.
            if ($kind === MessageKind::Public && $author->isInternal() === false && $ticket->status === TicketStatus::PendingCustomer) {
                $ticket->status = TicketStatus::InProgress;

                $event = new TicketEvent;
                $event->ticket_id = $ticket->id;
                $event->actor_id = $author->id;
                $event->type = TicketEventType::StatusChanged;
                $event->data = ['from' => TicketStatus::PendingCustomer->value, 'to' => TicketStatus::InProgress->value, 'automatic' => true];
                $event->visible_to_customer = true;
                $event->save();
            }

            $ticket->last_activity_at = now();
            $ticket->save();

            return $message;
        });
    }
}
