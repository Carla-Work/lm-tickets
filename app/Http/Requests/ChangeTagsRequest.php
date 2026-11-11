<?php

/*
 * Validación del cambio de etiquetas internas. Cuándo tocarla: al cambiar el formulario.
 * Trampas: autoriza con la policy ANTES de validar; `etiquetas` puede faltar (todas desmarcadas = quitar todas). Que las
 * etiquetas existan, estén activas y no pasen de 5 lo comprueba ChangeTags con el ticket bloqueado.
 */

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

class ChangeTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('changeTags', $ticket) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'etiquetas' => ['nullable', 'array', 'max:20'],
            'etiquetas.*' => ['integer'],
        ];
    }
}
