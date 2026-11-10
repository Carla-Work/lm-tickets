<?php

/*
 * Validación del cambio de departamento: departamento nuevo y una categoría de ese departamento (R6).
 * Cuándo tocarla: al cambiar el formulario. ChangeDepartment vuelve a comprobar la coherencia categoría–departamento.
 */

namespace App\Http\Requests;

use App\Enums\Department;
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
            'departamento' => ['required', Rule::enum(Department::class)],
            'categoria' => ['required', 'integer', Rule::exists(Category::class, 'id')],
        ];
    }
}
