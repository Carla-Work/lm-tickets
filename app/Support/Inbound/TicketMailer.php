<?php

/*
 * Envía por correo la respuesta del equipo al cliente y el rebote de «ticket cerrado». Cuándo tocarlo: al cambiar de qué dirección salen, cómo se enlazan
 * al hilo o el tope de tamaño.
 *
 * Trampas:
 *  - Solo se envía una respuesta PÚBLICA de una persona del EQUIPO que marcó «Enviar por correo» (ReplyToTicket lo garantiza); una nota (R2) nunca.
 *  - Va SOLO al SOLICITANTE (no a compañeros ni al administrador de su empresa), como los demás avisos.
 *  - Sale de la dirección del buzón del departamento (aplicaciones@, soporte@, comercial@): el cliente contesta ahí y la respuesta vuelve a entrar. El servidor
 *    SMTP tiene que permitir enviar con esas direcciones (guía de producción).
 *  - Cada correo lleva su Message-ID (se guarda en `ticket_messages.email_message_id`) y In-Reply-To/References con los anteriores: así se une el hilo.
 *  - Tope de tamaño (config inbound.max_outgoing_mb): StoreMessageRequest lo avisa antes; aquí se vuelve a comprobar y NO se envía si lo supera
 *    (el mensaje queda guardado en el ticket).
 */

namespace App\Support\Inbound;

use App\Enums\Department;
use App\Mail\ClosedTicketBounceMail;
use App\Mail\TicketReplyMail;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TicketMailer
{
    /** Dirección del buzón que atiende un departamento (Páginas web comparte buzón con Aplicaciones: mismo equipo). */
    public static function addressFor(Department $department): string
    {
        $wanted = $department === Department::Websites ? Department::WebApps : $department;
        foreach ((array) config('inbound.mailboxes') as $mailbox) {
            if (($mailbox['department'] ?? null) === $wanted->value) {
                return (string) $mailbox['address'];
            }
        }

        return (string) config('mail.from.address');
    }

    /** Bytes de adjuntos que viajarían en el correo. */
    public static function attachmentBytes(TicketMessage $message): int
    {
        return (int) $message->attachments()->sum('size');
    }

    /** @return bool  true si se envió (se puso en cola); false si no (p. ej. supera el tope). */
    public function sendReply(TicketMessage $message): bool
    {
        $message->loadMissing(['ticket.requester', 'author', 'attachments']);
        $ticket = $message->ticket;
        $requester = $ticket->requester;

        if (self::attachmentBytes($message) > (int) config('inbound.max_outgoing_mb') * 1048576) {
            return false;
        }

        // Hilo: el último mensaje con Message-ID (del cliente o nuestro) y todos los anteriores.
        $previous = TicketMessage::query()->where('ticket_id', $ticket->id)->whereNotNull('email_message_id')->orderBy('id')->pluck('email_message_id')->all();
        $messageId = 'tk-'.$ticket->id.'-'.Str::lower(Str::random(16)).'@'.config('inbound.internal_domain');

        // R2: lo que se cita es SIEMPRE el último mensaje PÚBLICO del cliente.
        $last = TicketMessage::query()->public()->where('ticket_id', $ticket->id)->where('id', '<', $message->id)->where('author_id', $requester->id)->latest('id')->first();

        $message->forceFill(['email_message_id' => $messageId, 'emailed_at' => now()])->save();

        $mail = new TicketReplyMail(
            messageId: $messageId,
            inReplyTo: $previous === [] ? null : end($previous),
            references: $previous,
            fromAddress: self::addressFor($ticket->department),
            subjectLine: 'Re: '.$ticket->subject.' [#'.$ticket->number.']',
            ticketNumber: $ticket->number,
            recipientName: $requester->name,
            senderName: $message->author->name,
            text: $message->body,
            files: $message->attachments->map(fn (Attachment $a) => ['disk' => Attachment::DISK, 'path' => $a->path, 'name' => $a->original_name, 'mime' => $a->mime_type])->all(),
            quote: $last === null ? null : ['author' => $requester->name, 'when' => $last->created_at->timezone(config('app.timezone'))->translatedFormat('j M, H:i'), 'text' => Str::limit($last->body, 600)],
        );
        Mail::to($requester->email, $requester->name)->queue($mail);

        return true;
    }

    /** Rebote «ticket cerrado»: como mucho UNO por ticket y día (evita bucles y no se usa de arma contra el solicitante). */
    public function sendClosedBounce(Ticket $ticket): bool
    {
        $key = "inbound-bounce:{$ticket->id}:".now()->format('Y-m-d');
        if (! Cache::add($key, 1, now()->addDay())) {
            return false;
        }

        $requester = $ticket->requester;
        Mail::to($requester->email, $requester->name)->queue(new ClosedTicketBounceMail(
            fromAddress: self::addressFor($ticket->department),
            subjectLine: 'Re: '.$ticket->subject.' [#'.$ticket->number.'] — ticket cerrado',
            ticketNumber: $ticket->number,
            recipientName: $requester->name,
        ));

        return true;
    }
}
