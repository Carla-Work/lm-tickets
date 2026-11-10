<?php

/*
 * Una fila del registro de correos (sección «Registros»). Cuándo tocarlo: al añadir un estado o un tipo.
 * Las filas las escribe App\Support\MailLogger; esta pantalla es de solo lectura.
 *
 * Estados: pending (abierto antes de enviar), sent (confirmado), failed (la cola informó del fallo).
 * Un correo «pending» que nunca se confirma es el que se perdió: por eso se abre ANTES de enviar.
 */

namespace App\Models;

use App\Notifications\InvitationNotification;
use App\Notifications\RecoveryCodeUsedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TicketNotice;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public const UPDATED_AT = null;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Nombre legible de cada tipo de correo (clase de la notificación). Lo que no figure sale como «Otro». */
    public const KINDS = [
        TicketNotice::class => 'Aviso de ticket',
        InvitationNotification::class => 'Invitación',
        ResetPasswordNotification::class => 'Recuperar contraseña',
        RecoveryCodeUsedNotification::class => 'Código de recuperación usado',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind ?? ''] ?? 'Otro';
    }
}
