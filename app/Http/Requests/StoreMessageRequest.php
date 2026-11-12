<?php

/*
 * Validación de un mensaje del hilo (respuesta pública o nota interna). Cuándo tocarla: al cambiar los límites
 * del texto o de los adjuntos (AttachmentRules). La autorización (quién puede escribir ahí) NO va aquí: la hace la
 * policy en el controlador y otra vez la acción bajo bloqueo.
 */

namespace App\Http\Requests;

use App\Support\AttachmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'mensaje' => ['required', 'string', 'max:10000'],
            // «Enviar por correo» (solo el equipo): si va marcada, los adjuntos viajan por correo y no pueden pasar del tope (config/inbound.php).
            'enviar_correo' => ['nullable', 'boolean'],
        ] + AttachmentRules::rules();
        $rules['adjuntos'][] = $this->emailSize(); // se SUMA a las reglas de siempre (máx. archivos y total), no las sustituye

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mensaje' => 'mensaje'] + AttachmentRules::attributes();
    }

    private function emailSize(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $this->boolean('enviar_correo') || $this->user()?->isInternal() !== true) {
                return;
            }
            $bytes = collect(is_array($value) ? $value : [])->sum(fn ($file) => $file instanceof UploadedFile ? (int) $file->getSize() : 0);
            $limit = (int) config('inbound.max_outgoing_mb');
            if ($bytes > $limit * 1048576) {
                $fail("Los adjuntos pesan demasiado para enviarse por correo (máximo {$limit} MB). Quita archivos o desmarca «Enviar por correo».");
            }
        };
    }
}
