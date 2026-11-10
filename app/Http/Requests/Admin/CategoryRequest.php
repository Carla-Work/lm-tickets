<?php

/*
 * Validación de categoría (alta y edición). El nombre es único DENTRO de su departamento (así lo exige la base
 * de datos) y en el alta el departamento es uno de los cuatro fijos.
 * Cuándo tocarla: al cambiar los datos de la categoría. La autorización la hace el controlador (policy).
 */

namespace App\Http\Requests\Admin;

use App\Enums\Department;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizar ANTES de validar: así quien no puede recibe 403 y no los mensajes de validación.
        $category = $this->route('category');

        return $category instanceof Category ? ($this->user()?->can('update', $category) ?? false) : ($this->user()?->can('create', Category::class) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $category = $this->route('category');
        $department = $category instanceof Category ? $category->department->value : $this->input('department');

        return [
            // En la edición el departamento sale de la categoría y no se acepta del formulario.
            'department' => $category instanceof Category ? ['prohibited'] : ['required', Rule::enum(Department::class)],
            'name' => ['required', 'string', 'max:100', Rule::unique(Category::class, 'name')
                ->where('department', is_string($department) ? $department : '')
                ->ignore($category instanceof Category ? $category->id : null)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'department' => 'departamento'];
    }
}
