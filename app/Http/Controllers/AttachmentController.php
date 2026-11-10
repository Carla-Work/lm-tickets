<?php

/*
 * Descarga de un adjunto (R3). Cuándo tocarlo: al cambiar cómo se sirven los archivos.
 *
 * Trampas:
 *  - La autorización es AttachmentPolicy::download (que mira si la persona puede ver el MENSAJE: las notas
 *    internas y sus adjuntos nunca llegan al cliente, R2). Sin ella, cualquiera con la URL bajaría el archivo.
 *  - Se sirve SIEMPRE como descarga (`attachment`) y con `nosniff`, para que el navegador no interprete jamás un
 *    archivo subido como una página (defensa extra además de la lista cerrada de tipos).
 *  - La ruta sale de la base de datos, nunca de la petición: no hay forma de pedir «../../.env».
 */

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment);

        $disk = Storage::disk(Attachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
