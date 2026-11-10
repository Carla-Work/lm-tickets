<?php

/*
 * Validación del alta de empresa: sus datos, productos opcionales y el primer usuario (administrador de empresa).
 * Cuándo tocarla: al cambiar el formulario «Nueva empresa». La autorización la hace el controlador (policy).
 */

namespace App\Http\Requests\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    use CompanyDataRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false; // 403 antes que los mensajes de validación
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->companyRules() + $this->productRules() + [
            'user_name' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
        ];
    }
}
