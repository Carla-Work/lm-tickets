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
 *  - `$sendByEmail` (P1, 2026-11-12): solo una respuesta PÚBLICA de una persona del equipo; la nota interna NUNCA sale por correo (R2).
 *  - Adjuntos: solo en respuestas PÚBLICAS (las notas internas no llevan, decidido); si la transacción falla se
 *    borran los ficheros ya escritos.
 */

namespace App\Actions\Tickets;

use App\Enums\MessageKind;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Inbound\TicketMailer;
use App\Support\TicketNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ReplyToTicket
{
    public function __construct(private readonly StoreAttachments $storeAttachments, private readonly TicketNotifier $notifier, private readonly TicketMailer $mailer) {}

    /** @param  list<UploadedFile>  $files */
    public function __invoke(User $author, Ticket $ticket, string $body, MessageKind $kind = MessageKind::Public, array $files = [], bool $sendByEmail = false, ?string $emailMessageId = null): TicketMessage
    {
        $paths = [];

        try {
            $message = $this->reply($author, $ticket, $body, $kind, $kind === MessageKind::Public ? $files : [], $emailMessageId, $paths);
        } catch (\Throwable $e) {
            Storage::disk(Attachment::DISK)->delete($paths); // sin esto quedarían ficheros de clientes sin dueño

            throw $e;
        }

        // R2: una NOTA INTERNA no avisa a nadie. Solo los mensajes públicos llegan al notificador.
        if ($kind === MessageKind::Public) {
            // «Enviar por correo» (P1): solo una respuesta PÚBLICA del EQUIPO; el correo lleva el texto y los adjuntos. Un fallo al enviar no
            // deshace la respuesta (ya está guardada): se registra y el agente la ve en el hilo sin la marca de «enviado por correo».
            if ($sendByEmail && $author->isInternal()) {
                try {
                    $this->mailer->sendReply($message);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $this->notifier->replied($message->ticket()->firstOrFail(), $author);
        }

        return $message;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $paths
     */
    private function reply(User $author, Ticket $ticket, string $body, MessageKind $kind, array $files, ?string $emailMessageId, array &$paths): TicketMessage
    {
        return DB::transaction(function () use ($author, $ticket, $body, $kind, $files, $emailMessageId, &$paths): TicketMessage {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($author)->authorize($kind === MessageKind::Internal ? 'addNote' : 'reply', $ticket);

            $message = new TicketMessage(['body' => $body]);
            $message->ticket_id = $ticket->id;
            $message->author_id = $author->id;
            $message->kind = $kind;
            // Respuesta del CLIENTE que entró por correo: guarda su Message-ID (única: el mismo correo no se añade dos veces).
            $message->email_message_id = $emailMessageId;
            $message->via_email = $emailMessageId !== null;
            $message->save();
            ($this->storeAttachments)($message, $author, $files, $paths);

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
