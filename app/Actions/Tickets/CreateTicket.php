<?php

/*
 * Abrir un ticket: crea el ticket, su primer mensaje (la descripción) y el primer evento del historial.
 * Cuándo tocarlo: al cambiar qué ocurre al abrir un ticket (p. ej. asignación automática o avisos, paso 5).
 *
 * Trampas:
 *  - Todo va en UNA transacción: un ticket sin su primer mensaje (o sin evento) es un ticket roto, y el
 *    número reservado por TicketSequence se desharía junto con el resto.
 *  - Esta acción NO valida departamento/categoría/producto (R6): lo hace StoreTicketRequest. Si algún día se
 *    llama desde otro sitio (p. ej. una importación), ese sitio debe validar lo mismo.
 *  - La empresa sale del SOLICITANTE, nunca de un dato enviado por el formulario (R1).
 */

namespace App\Actions\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTicket
{
    public function __invoke(User $requester, Department $department, Category $category, ?Product $product, string $subject, string $body): Ticket
    {
        return DB::transaction(function () use ($requester, $department, $category, $product, $subject, $body): Ticket {
            $ticket = new Ticket(['subject' => $subject]);
            // Fuera de fillable a propósito: solo el código de servidor fija estos datos.
            $ticket->company_id = $requester->company_id;
            $ticket->requester_id = $requester->id;
            $ticket->department = $department;
            $ticket->category_id = $category->id;
            $ticket->product_id = $product?->id;
            $ticket->status = TicketStatus::Open;
            $ticket->save();

            $message = new TicketMessage(['body' => $body]);
            $message->ticket_id = $ticket->id;
            $message->author_id = $requester->id;
            $message->kind = MessageKind::Public;
            $message->save();

            // Historial (R5): solo se añade. El cliente ve que su ticket se creó y en qué departamento.
            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $requester->id;
            $event->type = TicketEventType::Created;
            $event->data = ['department' => $department->value];
            $event->visible_to_customer = true;
            $event->save();

            return $ticket;
        });
    }
}
