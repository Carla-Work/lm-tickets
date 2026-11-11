<?php

/*
 * Validación del cambio de prioridad interna. Cuándo tocarla: al cambiar el formulario.
 * Trampa: autoriza con la policy ANTES de validar (como los demás FormRequest de gestión): quien no puede recibe 403 y no
 * mensajes de validación. La acción vuelve a comprobar el permiso con el ticket bloqueado.
 */

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('changePriority', $ticket) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['prioridad' => ['required', 'string', Rule::in(array_column(Priority::cases(), 'value'))]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['prioridad' => 'prioridad'];
    }
}
