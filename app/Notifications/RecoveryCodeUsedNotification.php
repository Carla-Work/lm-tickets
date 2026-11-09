<?php

/*
 * Aviso por correo de que se ha usado un código de recuperación del segundo factor (R21).
 * Cuándo tocarlo: al cambiar el texto.
 *
 * Por qué: entrar con un código de recuperación es lo que haría alguien que ha perdido el móvil... o
 * alguien que se ha hecho con los códigos. Que el administrador reciba un correo cada vez permite
 * darse cuenta de lo segundo. (Propuesta de Claude validada con los mockups.)
 * Pendiente: plantilla de correo por defecto de Laravel, sin mockup de correo.
 */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecoveryCodeUsedNotification extends Notification
{
    public function __construct(public readonly int $remaining) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Se ha usado un código de recuperación de tu cuenta')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Se acaba de entrar en tu cuenta de Ling Marco Tickets con un código de recuperación de la verificación en dos pasos.')
            ->line("Te quedan {$this->remaining} códigos sin usar.")
            ->line('Si has sido tú, no tienes que hacer nada. Si NO has sido tú, avisa cuanto antes a quien administra el servidor: alguien podría tener tus códigos.');
    }
}
