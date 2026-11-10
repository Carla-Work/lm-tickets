<?php

/*
 * Reglas y limpieza compartidas por el alta y la edición de empresa.
 * Cuándo tocarlo: al cambiar los datos de la empresa (y entonces también la migración y Company::$fillable).
 */

namespace App\Http\Requests\Admin;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Validation\Rule;

trait CompanyDataRules
{
    /** El CIF se guarda en mayúsculas y sin espacios: «b35 123456» y «B35123456» son la misma empresa. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('tax_id')) {
            return;
        }

        $this->merge(['tax_id' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('tax_id')) ?? '')]);
    }

    /** @return array<string, list<mixed>> */
    protected function companyRules(?Company $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['required', 'string', 'max:20', Rule::unique(Company::class, 'tax_id')->ignore($ignore?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function productRules(): array
    {
        return [
            'productos' => ['nullable', 'array'],
            'productos.*' => ['integer', Rule::exists(Product::class, 'id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'tax_id' => 'CIF', 'phone' => 'teléfono', 'contact_email' => 'correo de contacto',
            'user_name' => 'nombre del primer usuario', 'user_email' => 'correo del primer usuario', 'productos.*' => 'producto'];
    }
}
