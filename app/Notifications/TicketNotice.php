<?php

/*
 * Aviso de un ticket: una entrada en la campana (tabla `notifications`) y un correo.
 * Cuándo tocarla: al cambiar el aspecto del correo o lo que se guarda en la campana. QUIÉN recibe QUÉ y los
 * textos están en App\Support\TicketNotifier, no aquí.
 *
 * Trampas:
 *  - PRIVACIDAD: ni el correo ni la campana llevan el CUERPO de los mensajes (decidido 2026-11-10): el correo viaja
 *    en claro y el contenido de un ticket es dato de cliente. Llevan número, asunto, quién y un enlace; para leer
 *    hay que entrar con la cuenta. Tampoco llevan nunca una nota interna (R2): el notificador no avisa de ellas.
 *  - Va a la COLA y después del commit (`afterCommit`): si la transacción del ticket se deshace no sale nada, y si el
 *    servidor de correo falla no rompe la acción de quien la hizo. En producción el trabajador de colas tiene que
 *    estar en marcha (sin él no salen ni los correos ni las entradas de la campana).
 *  - `ticket_id` va en los datos para poder ocultar el aviso a quien ya no puede ver ese ticket (R1, R13).
 */

namespace App\Notifications;

use App\Enums\TicketNoticeKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TicketNoticeKind $kind,
        public readonly int $ticketId,
        public readonly string $title,
        public readonly string $detail,
        public readonly bool $mail = true, // false: solo la campana (la respuesta del equipo va por su propio correo, o el agente decidió no enviarla)
    ) {
        $this->afterCommit(); // ver «Trampas»: nada sale si la transacción se deshace
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->mail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Ling Marco Tickets] '.$this->title)
            ->greeting('Hola, '.$notifiable->name)
            ->line($this->title)
            ->line($this->detail)
            ->action('Ver el ticket', route('tickets.show', $this->ticketId))
            ->line('Por seguridad, el correo no incluye el contenido del ticket: entra con tu cuenta para leerlo.')
            ->salutation('Ling Marco & Asociados');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind->value, 'ticket_id' => $this->ticketId, 'title' => $this->title, 'detail' => $this->detail];
    }
}
