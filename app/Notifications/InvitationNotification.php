<?php

/*
 * Correo de invitación a una cuenta nueva (R18). Qué es: el enlace para elegir la primera contraseña.
 * Cuándo tocarlo: al cambiar el texto o la caducidad (broker «invitations» de config/auth.php).
 *
 * Pendiente: plantilla por defecto de Laravel, sin mockup de correo (ver ResetPasswordNotification).
 */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dias = (int) round(config('auth.passwords.invitations.expire') / (60 * 24));
        $enlace = route('invitation.accept', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Te han invitado a Ling Marco Tickets')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Se ha creado una cuenta para ti en Ling Marco Tickets, el panel de soporte de Ling Marco & Asociados.')
            ->action('Crear mi contraseña', $enlace)
            ->line("El enlace caduca en {$dias} días. Si caduca, pide que te envíen otra invitación.")
            ->line('Si no esperabas este correo, puedes ignorarlo.');
    }
}
