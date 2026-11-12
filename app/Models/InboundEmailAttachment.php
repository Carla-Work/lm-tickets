<?php

/*
 * Adjunto de un correo en cuarentena, guardado en el disco PRIVADO (R3) hasta que el administrador decide. Cuándo tocarlo: al cambiar cómo se guardan.
 * Al crear el ticket desde la cuarentena se COPIA a los adjuntos del ticket; al purgar el correo se borra el fichero (retention:run).
 * Solo entra aquí lo que pasó la misma lista cerrada de tipos que los adjuntos de un ticket (AllowedAttachment).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundEmailAttachment extends Model
{
    /** @return BelongsTo<InboundEmail, $this> */
    public function email(): BelongsTo
    {
        return $this->belongsTo(InboundEmail::class, 'inbound_email_id');
    }
}
