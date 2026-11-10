<?php

/*
 * Validación de un mensaje del hilo (respuesta pública o nota interna). Cuándo tocarla: al cambiar los límites
 * del texto o al añadir adjuntos (4d). La autorización (quién puede escribir ahí) NO va aquí: la hace la
 * policy en el controlador y otra vez la acción bajo bloqueo.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['mensaje' => ['required', 'string', 'max:10000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mensaje' => 'mensaje'];
    }
}
