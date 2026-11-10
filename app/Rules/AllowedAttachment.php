<?php

/*
 * Regla de validación de UN archivo adjunto: extensión permitida Y contenido real coherente con ella.
 * Cuándo tocarla: casi nunca; los tipos y tamaños se cambian en config/access.php ('attachments').
 *
 * Trampa: no basta con mirar la extensión (un `.php` renombrado a `.png` pasaría) ni el tipo que declara el
 * navegador (lo escribe quien sube el archivo). Se mira el contenido con finfo (`getMimeType`) y debe estar
 * entre los tipos que la configuración acepta PARA ESA extensión.
 */

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class AllowedAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('El adjunto no es un archivo válido.');

            return;
        }

        $name = $value->getClientOriginalName();
        /** @var array<string, list<string>> $types */
        $types = config('access.attachments.types');
        $extension = strtolower($value->getClientOriginalExtension());

        if (! isset($types[$extension])) {
            $fail("«{$name}» no es de un tipo permitido. Admitimos: ".implode(', ', array_keys($types)).'.');

            return;
        }

        if (! in_array($value->getMimeType(), $types[$extension], true)) {
            $fail("El contenido de «{$name}» no corresponde a un archivo .{$extension}.");
        }
    }
}
