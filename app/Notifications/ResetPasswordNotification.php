<?php

/*
 * Correo de «he olvidado mi contraseña». Qué es: el enlace para crear una contraseña nueva.
 * Cuándo tocarlo: al cambiar el texto o la caducidad (config/auth.php).
 *
 * Pendiente: el correo usa la plantilla por defecto de Laravel. No hay mockup de correos
 * (nada con diseño sin mockup): su aspecto se decidirá con la usuaria.
 */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.users.expire');
        $enlace = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Crea una contraseña nueva')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Hemos recibido una petición para cambiar la contraseña de tu cuenta en Ling Marco Tickets.')
            ->action('Crear contraseña nueva', $enlace)
            ->line("El enlace caduca en {$minutos} minutos y solo se puede usar una vez.")
            ->line('Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.');
    }
}
