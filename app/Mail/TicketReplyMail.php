<?php

/*
 * Correo con la RESPUESTA del equipo al cliente: lleva el TEXTO y los adjuntos (excepción decidida por la usuaria, 2026-11-12, porque el
 * cliente no entra al panel). Cuándo tocarlo: al cambiar el aspecto del correo (mockup correo-saliente-a-respuesta-y-rebote.html).
 *
 * Trampas:
 *  - Solo lo envía TicketMailer::sendReply con una respuesta PÚBLICA marcada por el agente; una nota interna nunca llega aquí (R2).
 *  - Lleva su Message-ID y In-Reply-To/References: así la respuesta del cliente (que su correo pone en In-Reply-To) entra en su hilo. Sin ellos un
 *    correo nuevo no se une a ningún ticket.
 *  - Cabecera X-App-No-Body: el registro de correos NO guarda el cuerpo (es dato de cliente; MailLogger).
 *  - `$quote` es el último mensaje PÚBLICO del cliente (nunca una nota). Todo se escapa en la vista.
 *  - Va a la COLA: en producción el trabajador de colas tiene que estar en marcha.
 */

namespace App\Mail;

use App\Support\MailLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $references
     * @param  list<array{disk: string, path: string, name: string, mime: string}>  $files
     * @param  array{author: string, when: string, text: string}|null  $quote
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromAddress,
        public readonly string $subjectLine,
        public readonly string $ticketNumber,
        public readonly string $recipientName,
        public readonly string $senderName,
        public readonly string $text,
        public readonly array $files = [],
        public readonly ?array $quote = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, 'Ling Marco Soporte'),
            replyTo: [new Address($this->fromAddress, 'Ling Marco Soporte')],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: array_filter([
                'In-Reply-To' => $this->inReplyTo === null ? null : "<{$this->inReplyTo}>",
                MailLogger::NO_BODY_HEADER => '1',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-reply');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $f) => Attachment::fromStorageDisk($f['disk'], $f['path'])->as($f['name'])->withMime($f['mime']),
            $this->files,
        );
    }
}
