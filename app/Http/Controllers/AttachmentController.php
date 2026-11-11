<?php

/*
 * Descarga de un adjunto (R3). Cuándo tocarlo: al cambiar cómo se sirven los archivos.
 *
 * Trampas:
 *  - La autorización es AttachmentPolicy::download (que mira si la persona puede ver el MENSAJE: las notas
 *    internas y sus adjuntos nunca llegan al cliente, R2). Sin ella, cualquiera con la URL bajaría el archivo.
 *  - Se sirve SIEMPRE como descarga (`attachment`) y con `nosniff`, para que el navegador no interprete jamás un
 *    archivo subido como una página (defensa extra además de la lista cerrada de tipos).
 *  - Una descarga DENEGADA queda en la auditoría (`attachment_denied`: quién y qué adjunto; defecto D-4, decidido
 *    2026-11-11): es la señal de alguien probando URLs. Las descargas permitidas NO se auditan (llenarían la tabla).
 *  - La ruta sale de la base de datos, nunca de la petición: no hay forma de pedir «../../.env».
 */

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $decision = Gate::inspect('download', $attachment);
        if ($decision->denied()) {
            // Solo ids: ni el nombre del fichero ni el asunto del ticket (datos del cliente) van a la auditoría.
            Audit::record('attachment_denied', 'Descarga de un adjunto denegada (R3).', $request->user(), null, $request->ip(), ['attachment_id' => $attachment->id, 'ticket_id' => $attachment->ticket_id]);
        }
        $decision->authorize(); // lanza el 403 de siempre si estaba denegada

        $disk = Storage::disk(Attachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
