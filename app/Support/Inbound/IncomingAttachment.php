<?php

/*
 * Adjunto de un correo recibido, ya volcado a un fichero TEMPORAL. Cuándo tocarlo: al cambiar cómo se leen los adjuntos.
 * Trampa: el nombre y el tipo los escribe el remitente: no se fía de ninguno. Quien lo use lo valida con AllowedAttachment (extensión Y contenido real).
 */

namespace App\Support\Inbound;

use Illuminate\Http\UploadedFile;

final class IncomingAttachment
{
    public function __construct(public readonly string $name, public readonly string $path) {}

    /** Como si lo hubiera subido un formulario, para pasarlo por las MISMAS reglas y por StoreAttachments que cualquier adjunto. */
    public function asUploadedFile(): UploadedFile
    {
        return new UploadedFile($this->path, $this->name, null, null, true);
    }
}
