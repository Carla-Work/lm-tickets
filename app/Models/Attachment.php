<?php

/*
 * Adjunto de un mensaje. Guarda solo la RUTA en el disco privado; el fichero nunca se sirve por URL
 * pública (R3): se descarga por un controlador que comprueba permisos.
 * Cuándo tocarlo: al cambiar cómo se guardan o se descargan los adjuntos.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    public const UPDATED_AT = null;

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
