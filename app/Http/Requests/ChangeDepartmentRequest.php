<?php

/*
 * Validación del cambio de departamento: solo llega la CATEGORÍA nueva; el departamento sale de ella (R6).
 * Cuándo tocarla: al cambiar el formulario. Trampa: no volver a aceptar un `departamento` enviado aparte; así no
 * existe la combinación incoherente. ChangeDepartment vuelve a comprobar categoría activa y departamento distinto.
 */

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'categoria' => ['required', 'integer', Rule::exists(Category::class, 'id')->where('is_default', false)],
        ];
    }
}
