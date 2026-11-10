<?php

/*
 * Validación de un cambio de estado: el estado de destino y, si es «Resuelto», cómo se resolvió (R16).
 * Cuándo tocarla: al cambiar los campos del formulario de estado. El cliente usa la misma petición con el
 * estado fijado a «Resuelto» por el controlador (nunca se fía de lo que envíe).
 */

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'estado' => ['sometimes', 'required', Rule::enum(TicketStatus::class)],
            'solucion' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
