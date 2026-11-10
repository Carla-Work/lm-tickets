<?php

/*
 * Guarda los archivos subidos junto a un mensaje: el fichero en el disco PRIVADO y una fila `attachments` por archivo.
 * Cuándo tocarla: al cambiar dónde o cómo se guardan los adjuntos.
 *
 * Trampas:
 *  - R3: SIEMPRE el disco privado (Attachment::DISK, storage/app/private). Nunca `public`.
 *  - El nombre en disco lo pone `putFile` (aleatorio): el nombre que eligió el usuario no toca el sistema de
 *    ficheros (ni `../`, ni caracteres raros). El nombre original solo se guarda como TEXTO para mostrarlo.
 *  - `mime_type` se guarda el DETECTADO por contenido, no el que declaró el navegador.
 *  - Los ficheros se escriben antes del COMMIT: si la transacción falla, quien llama debe borrar lo devuelto en
 *    `$paths` (ver CreateTicket y ReplyToTicket), o quedarían ficheros huérfanos con datos de clientes.
 */

namespace App\Actions\Tickets;

use App\Models\Attachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreAttachments
{
    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $paths  se rellena (por referencia) con las rutas guardadas, para limpiar si falla algo
     */
    public function __invoke(TicketMessage $message, User $uploader, array $files, array &$paths): void
    {
        foreach ($files as $file) {
            $path = Storage::disk(Attachment::DISK)->putFile("attachments/{$message->ticket_id}", $file);
            if ($path === false) {
                throw new \RuntimeException('No se pudo guardar el adjunto.');
            }
            $paths[] = $path;

            $attachment = new Attachment;
            $attachment->ticket_id = $message->ticket_id;
            $attachment->ticket_message_id = $message->id;
            $attachment->uploaded_by = $uploader->id;
            $attachment->original_name = $this->cleanName($file->getClientOriginalName());
            $attachment->path = $path;
            $attachment->mime_type = (string) $file->getMimeType();
            $attachment->size = (int) $file->getSize();
            $attachment->save();
        }
    }

    /** Solo el nombre (sin ruta), sin caracteres de control, y no más largo de lo razonable. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);

        return Str::limit(trim($name) === '' ? 'adjunto' : trim($name), 150, '');
    }
}
