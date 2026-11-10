<?php

/*
 * Validación de producto (alta y edición): nombre único y descripción corta opcional.
 * Cuándo tocarla: al cambiar los datos del producto. La autorización la hace el controlador (policy).
 */

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizar ANTES de validar: así quien no puede recibe 403 y no los mensajes de validación (p. ej. «ya existe»).
        $product = $this->route('product');

        return $product instanceof Product ? ($this->user()?->can('update', $product) ?? false) : ($this->user()?->can('create', Product::class) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(Product::class, 'name')->ignore($product instanceof Product ? $product->id : null)],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'description' => 'descripción'];
    }
}
