<?php

/*
 * Validación de etiqueta (alta y cambio de nombre): nombre corto y único. Cuándo tocarla: al cambiar los datos de la etiqueta.
 * Trampa: autoriza con la policy ANTES de validar, para que quien no puede reciba 403 y no «ya existe esa etiqueta».
 */

namespace App\Http\Requests\Admin;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tag = $this->route('tag');

        return $tag instanceof Tag ? ($this->user()?->can('update', $tag) ?? false) : ($this->user()?->can('create', Tag::class) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => ['required', 'string', 'max:'.Tag::NAME_MAX, Rule::unique(Tag::class, 'name')->ignore($tag instanceof Tag ? $tag->id : null)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre'];
    }
}
