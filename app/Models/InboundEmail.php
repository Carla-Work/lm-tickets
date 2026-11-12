<?php

/*
 * Un correo recibido en uno de los buzones compartidos (aplicaciones@, soporte@, comercial@) y lo que se hizo con él.
 * Cuándo tocarlo: al cambiar el ciclo del correo entrante. Plan: docs/plan/p1-email-entrante.md.
 *
 * Trampas:
 *  - Los datos los fija SOLO el código de servidor (nada es asignable en masa): vienen de un remitente que no controlamos.
 *  - `body` solo se conserva en cuarentena (pending/spam). Al convertirse en ticket o mensaje se vacía: el texto vive en el ticket.
 *    Se pinta SIEMPRE escapado y como texto plano, nunca como HTML del remitente.
 *  - Estados (repetidos en el CHECK de la migración): pending (a revisar, 90 días) · spam (posible spam, 30 días) · ticket (creó un ticket)
 *    · reply (se añadió a un hilo) · ignored (compañero, autorrespuesta o rebote: no es de un cliente) · rejected (respuesta a un ticket final).
 *  - Departamento y R13: un correo de comercial@ es de Contabilidad; solo el administrador ve la cuarentena.
 */

namespace App\Models;

use App\Enums\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundEmail extends Model
{
    public const PENDING = 'pending';

    public const SPAM = 'spam';

    public const TICKET = 'ticket';

    public const REPLY = 'reply';

    public const IGNORED = 'ignored';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['to_addresses' => 'array', 'reasons' => 'array', 'auth_ok' => 'boolean', 'received_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** El departamento al que pertenece el buzón por el que llegó. */
    public function department(): ?Department
    {
        foreach ((array) config('inbound.mailboxes') as $mailbox) {
            if (($mailbox['key'] ?? null) === $this->mailbox) {
                return Department::tryFrom((string) $mailbox['department']);
            }
        }

        return null;
    }

    /** Dirección del buzón (para enseñarla), o la clave si ya no existe en la configuración. */
    public function mailboxAddress(): string
    {
        foreach ((array) config('inbound.mailboxes') as $mailbox) {
            if (($mailbox['key'] ?? null) === $this->mailbox) {
                return (string) $mailbox['address'];
            }
        }

        return $this->mailbox;
    }

    /**
     * Correos en cuarentena (lo que revisa el administrador).
     *
     * @param  Builder<InboundEmail>  $query
     * @return Builder<InboundEmail>
     */
    public function scopeQuarantined(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::SPAM]);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return HasMany<InboundEmailAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(InboundEmailAttachment::class);
    }
}
