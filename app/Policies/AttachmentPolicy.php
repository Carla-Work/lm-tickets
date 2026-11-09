<?php

/*
 * Quién puede descargar un adjunto (R3). La descarga irá por un controlador que llama aquí; el fichero
 * está en un disco PRIVADO y no tiene URL pública.
 * Cuándo tocarlo: nunca para abrir una descarga: ve el ticket Y el mensaje, o no hay descarga.
 * Un adjunto de una nota interna es, por herencia, solo del equipo (R2).
 */

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function download(User $user, Attachment $attachment): bool
    {
        return $user->can('view', $attachment->message);
    }
}
