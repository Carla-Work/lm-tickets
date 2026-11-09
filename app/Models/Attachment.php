<?php

/*
 * Adjunto de un mensaje. Guarda solo la RUTA en el disco privado; el fichero nunca se sirve por URL
 * pública (R3): se descarga por un controlador que comprueba permisos.
 * Cuándo tocarlo: al cambiar cómo se guardan o se descargan los adjuntos.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    public const UPDATED_AT = null;

    /**
     * Disco donde viven los ficheros: el PRIVADO (`local`, en storage/app/private), fuera de
     * public/. Nunca el disco `public`: un adjunto no debe tener nunca una URL abierta (R3).
     */
    public const DISK = 'local';

    /**
     * Los adjuntos que este usuario puede ver (R2, R3): los de notas internas son SOLO del equipo.
     * No comprueba que vea el ticket: se combina con Ticket::visibleTo.
     *
     * @param  Builder<Attachment>  $query
     * @return Builder<Attachment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isInternal() && $user->isOperational()) {
            return $query;
        }

        return $query->whereHas('message', fn (Builder $mensaje) => $mensaje->public());
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
