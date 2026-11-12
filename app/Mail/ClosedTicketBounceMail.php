<?php

/*
 * Rebote automático: el cliente respondió por correo a un ticket que ya está resuelto o cerrado (R4/R15: nadie lo reabre; abre uno nuevo).
 * Cuándo tocarlo: al cambiar el texto (mockup correo-saliente-a-respuesta-y-rebote.html).
 *
 * Trampas:
 *  - Solo se envía a quien pasó SPF/DKIM Y es el solicitante del ticket (TicketMailer/ProcessIncomingEmail): reenviar un rebote a una dirección
 *    falsificada sería mandar correo a un tercero. Como mucho uno por ticket y día (evita bucles con otro autorrespondedor).
 *  - No lleva contenido de ningún mensaje del ticket, solo el número y el asunto. Cabecera X-App-No-Body como el resto.
 */

namespace App\Mail;

use App\Support\MailLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class ClosedTicketBounceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $fromAddress,
        public readonly string $subjectLine,
        public readonly string $ticketNumber,
        public readonly string $recipientName,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, 'Ling Marco Soporte'), subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        // Auto-Submitted: otro autorrespondedor al otro lado no debe contestar a este aviso (bucles).
        return new Headers(text: ['Auto-Submitted' => 'auto-replied', MailLogger::NO_BODY_HEADER => '1']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-closed-bounce');
    }
}
