<?php

/*
 * Validación de la valoración de la atención. Cuándo tocarla: al cambiar el formulario.
 * Trampa: autoriza con la policy ANTES de validar: quien no puede valorar recibe 403 y no mensajes de validación.
 * RateTicket vuelve a comprobar el permiso con el ticket bloqueado y que no esté ya valorado.
 */

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Models\TicketRating;
use Illuminate\Foundation\Http\FormRequest;

class StoreRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('rate', $ticket) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'puntuacion' => ['required', 'integer', 'between:1,5'],
            'comentario' => ['nullable', 'string', 'max:'.TicketRating::COMMENT_MAX],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['puntuacion' => 'puntuación', 'comentario' => 'comentario'];
    }
}
