<?php

/*
 * Abrir un ticket: crea el ticket, su primer mensaje (la descripción) y el primer evento del historial.
 * Cuándo tocarlo: al cambiar qué ocurre al abrir un ticket (p. ej. asignación automática o avisos, paso 5).
 * Lo abre el CLIENTE (lo normal), una persona del EQUIPO a nombre de un cliente (`$staff`) o nace de un CORREO RECIBIDO (`$email`), P1 2026-11-12.
 *
 * Trampas:
 *  - Todo va en UNA transacción: un ticket sin su primer mensaje (o sin evento) es un ticket roto, y el
 *    número reservado por TicketSequence se desharía junto con el resto.
 *  - Esta acción NO valida departamento/categoría/producto (R6): lo hace StoreTicketRequest. Si algún día se
 *    llama desde otro sitio (p. ej. una importación), ese sitio debe validar lo mismo.
 *  - La empresa sale del SOLICITANTE, nunca de un dato enviado por el formulario (R1).
 *  - `$related` (R15/R16): el ticket nuevo se relaciona con uno resuelto o cerrado. Esta acción NO comprueba que
 *    se pueda (TicketPolicy::createRelated): lo hace StoreTicketRequest, igual que el resto de validaciones.
 *  - Los adjuntos van al primer mensaje; si la transacción falla se borran los ficheros ya escritos (StoreAttachments).
 *  - TICKET CREADO POR EL EQUIPO (`$staff`): el SOLICITANTE sigue siendo el cliente (empresa, avisos, lo que ve) y el primer
 *    mensaje y el evento visible «Created» van a SU nombre: el cliente ve el ticket como suyo (decidido 2026-11-12). Quién lo creó
 *    de verdad queda en `tickets.created_by_id` y en un evento INTERNO (`CreatedByStaff`, con canal y asignación, R5); el cliente
 *    no los ve en ninguna pantalla ni correo. Nace «Abierto» y ASIGNADO; sin avisar al equipo de «sin asignar». Al cliente solo
 *    se le avisa si quien lo crea lo marcó. Esta acción recomprueba aquí el permiso, el departamento, al cliente y a la persona
 *    asignada (dos capas: el FormRequest da el mensaje, esto impide saltárselo desde otro sitio).
 */

namespace App\Actions\Tickets;

use App\Enums\Department;
use App\Enums\MessageKind;
use App\Enums\TicketChannel;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\TicketNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateTicket
{
    public function __construct(private readonly StoreAttachments $storeAttachments, private readonly TicketNotifier $notifier) {}

    /** @param  list<UploadedFile>  $files */
    public function __invoke(User $requester, Department $department, Category $category, ?Product $product, string $subject, string $body, array $files = [], ?Ticket $related = null, ?StaffCreation $staff = null, ?EmailOrigin $email = null): Ticket
    {
        $paths = [];

        try {
            $ticket = $this->create($requester, $department, $category, $product, $subject, $body, $files, $related, $staff, $email, $paths);
        } catch (\Throwable $e) {
            Storage::disk(Attachment::DISK)->delete($paths); // sin esto quedarían ficheros de clientes sin dueño

            throw $e;
        }

        // DESPUÉS de la transacción: si se deshace, no sale ningún aviso.
        if ($staff === null && $email === null) {
            $this->notifier->created($ticket);
        } elseif ($staff === null) {
            $this->notifier->createdFromEmail($ticket);
        } else {
            $this->notifier->createdByStaff($ticket, $staff->actor, $staff->notifyCustomer);
        }

        return $ticket;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $paths
     */
    private function create(User $requester, Department $department, Category $category, ?Product $product, string $subject, string $body, array $files, ?Ticket $related, ?StaffCreation $staff, ?EmailOrigin $email, array &$paths): Ticket
    {
        return DB::transaction(function () use ($requester, $department, $category, $product, $subject, $body, $files, $related, $staff, $email, &$paths): Ticket {
            if ($staff !== null) {
                $requester = $this->guardStaffCreation($staff, $requester, $department);
            }

            $ticket = new Ticket(['subject' => $subject]);
            // Fuera de fillable a propósito: solo el código de servidor fija estos datos.
            $ticket->company_id = $requester->company_id;
            $ticket->requester_id = $requester->id;
            $ticket->created_by_id = $staff === null ? $requester->id : $staff->actor->id;
            $ticket->channel = $staff->channel ?? ($email === null ? TicketChannel::Web : TicketChannel::Email);
            $ticket->assigned_to = $staff?->assignee->id ?? $email?->assignee?->id;
            $ticket->department = $department;
            $ticket->category_id = $category->id;
            $ticket->product_id = $product?->id;
            $ticket->status = TicketStatus::Open;
            $ticket->related_ticket_id = $related?->id;
            $ticket->save();

            $message = new TicketMessage(['body' => $body]);
            $message->ticket_id = $ticket->id;
            $message->author_id = $requester->id;
            $message->kind = MessageKind::Public;
            // Si nace de un correo, guarda su Message-ID: las respuestas del cliente (cabecera In-Reply-To) entran en este hilo por él.
            $message->email_message_id = $email?->messageId;
            $message->via_email = $email !== null;
            $message->save();
            ($this->storeAttachments)($message, $staff === null ? $requester : $staff->actor, $files, $paths);

            // Historial (R5): solo se añade. El cliente ve que su ticket se creó y en qué departamento.
            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $requester->id;
            $event->type = TicketEventType::Created;
            $event->data = ['department' => $department->value] + ($related === null ? [] : ['related_ticket_id' => $related->id]);
            $event->visible_to_customer = true;
            $event->save();

            if ($staff !== null) {
                // Historial INTERNO (R5): quién lo creó a nombre del cliente, por qué canal y a quién se asignó. Los nombres
                // se copian para que el historial siga diciendo lo mismo si luego renombran a alguien.
                $internal = new TicketEvent;
                $internal->ticket_id = $ticket->id;
                $internal->actor_id = $staff->actor->id;
                $internal->type = TicketEventType::CreatedByStaff;
                $internal->data = [
                    'channel' => $staff->channel->value,
                    'requester_name' => $requester->name,
                    'to' => $staff->assignee->id,
                    'to_name' => $staff->assignee->name,
                ];
                $internal->visible_to_customer = false;
                $internal->save();
            }

            // Nació de un correo dirigido a un compañero: historial INTERNO de por qué está asignado (R5). Si lo creó el equipo desde la
            // cuarentena, la asignación ya consta en el evento `created_by_staff`.
            if ($staff === null && $email?->assignee !== null) {
                $assigned = new TicketEvent;
                $assigned->ticket_id = $ticket->id;
                $assigned->actor_id = $requester->id;
                $assigned->type = TicketEventType::Assigned;
                $assigned->data = ['from' => null, 'from_name' => null, 'to' => $email->assignee->id, 'to_name' => $email->assignee->name, 'reason' => 'email_recipient'];
                $assigned->visible_to_customer = false;
                $assigned->save();
            }

            return $ticket;
        });
    }

    /**
     * Comprobaciones de un ticket creado por el equipo, con datos releídos dentro de la transacción. Devuelve al cliente releído.
     *
     * @throws ValidationException
     */
    private function guardStaffCreation(StaffCreation $staff, User $requester, Department $department): User
    {
        Gate::forUser($staff->actor)->authorize('createForCustomer', Ticket::class);

        $customer = User::query()->find($requester->id);
        if ($customer === null || ! $customer->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin) || ! $customer->isOperational()) {
            throw ValidationException::withMessages(['solicitante' => 'Elige a una persona activa de una empresa activa.']);
        }
        // R11/R13: un agente solo crea en lo que atiende (el de Contabilidad, solo Contabilidad; los demás, nunca Contabilidad).
        if (! $staff->actor->canWorkOn($department)) {
            throw ValidationException::withMessages(['department' => 'No puedes crear tickets en ese departamento.']);
        }
        // Igual que AssignTicket: un agente en activo que atienda ese departamento (el administrador no atiende, R10).
        $assignee = User::query()->find($staff->assignee->id);
        if ($assignee === null || ! $assignee->hasRole(UserRole::Agent) || ! $assignee->isOperational() || ! $assignee->canWorkOn($department)) {
            throw ValidationException::withMessages(['asignado' => 'Solo se puede asignar a un agente en activo que atienda este departamento.']);
        }

        return $customer;
    }
}
