<?php

/*
 * Validación de «Asignado a»: un id de usuario o «sin» (dejar sin asignar). Que sea un agente en activo lo
 * comprueba AssignTicket (con mensaje), no aquí. Cuándo tocarla: al cambiar el formulario de asignación.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['asignado' => ['required', 'regex:/^(sin|\d+)$/']];
    }
}
