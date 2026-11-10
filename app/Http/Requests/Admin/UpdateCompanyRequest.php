<?php

/*
 * Validación de «Datos de la empresa» (detalle). Cuándo tocarla: al cambiar ese formulario.
 */

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use CompanyDataRules;

    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('update', $company) ?? false); // 403 antes que la validación
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $company = $this->route('company');

        return $this->companyRules($company instanceof Company ? $company : null);
    }
}
