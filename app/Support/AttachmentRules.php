<?php

/*
 * Reglas de validación de los adjuntos de un formulario (límites en config/access.php, 'attachments').
 * Cuándo tocarla: al cambiar el campo del formulario o añadir un límite nuevo.
 *
 * Se usa desde StoreTicketRequest (abrir ticket) y StoreMessageRequest (responder). La regla del total existe
 * porque 5 archivos de 10 MB (50 MB) superan el post_max_size de PHP: PHP descartaría la petición en silencio.
 */

namespace App\Support;

use App\Rules\AllowedAttachment;
use Closure;
use Illuminate\Http\UploadedFile;

final class AttachmentRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'adjuntos' => ['nullable', 'array', 'max:'.(int) config('access.attachments.max_files'), self::totalSize()],
            'adjuntos.*' => ['file', 'max:'.(int) config('access.attachments.max_kb'), new AllowedAttachment],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['adjuntos' => 'adjuntos', 'adjuntos.*' => 'adjunto'];
    }

    private static function totalSize(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $bytes = collect(is_array($value) ? $value : [])
                ->sum(fn ($file) => $file instanceof UploadedFile ? (int) $file->getSize() : 0);
            $limit = (int) config('access.attachments.max_total_kb') * 1024;
            if ($bytes > $limit) {
                $fail('Los adjuntos de este envío superan '.intdiv($limit, 1048576).' MB en total. Envía los archivos en mensajes distintos.');
            }
        };
    }
}
