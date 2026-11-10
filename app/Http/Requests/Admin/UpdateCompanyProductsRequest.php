<?php

/*
 * Validación de «Productos contratados» (detalle de empresa). Cuándo tocarla: al cambiar ese formulario.
 * El producto debe existir en el catálogo (R6/R7); que sea activo lo comprueba UpdateCompany.
 */

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyProductsRequest extends FormRequest
{
    use CompanyDataRules;

    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('assignProducts', $company) ?? false); // 403 antes que la validación
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->productRules();
    }
}
