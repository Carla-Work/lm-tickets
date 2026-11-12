<?php

/*
 * Validación de «Invitar al remitente» desde la cuarentena. Cuándo tocarla: al cambiar quién se puede invitar desde un correo.
 * R18: la cuenta nace SOLO por invitación (InviteUser). El correo debe ser nuevo (si ya existe una cuenta, de baja o no, el error es el mismo genérico) y no del
 * propio equipo. La empresa debe estar activa. Autoriza con la policy ANTES de validar.
 */

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteFromInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $email = $this->route('inboundEmail');

        return $email instanceof InboundEmail && ($this->user()?->can('decide', $email) ?? false);
    }

    /** La dirección a invitar es SIEMPRE la del correo recibido (se inyecta aquí para que la regla se ejecute aunque el formulario no la envíe). */
    protected function prepareForValidation(): void
    {
        $email = $this->route('inboundEmail');
        $this->merge(['remitente' => $email instanceof InboundEmail ? $email->from_email : '']);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer', Rule::exists(Company::class, 'id')->whereNull('deactivated_at')],
            'role' => ['required', Rule::in([UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])],
            // El correo sale del correo recibido, no del formulario: nadie invita a una dirección distinta de la que escribió.
            'remitente' => ['required', 'string', function (string $a, mixed $v, \Closure $fail): void {
                $email = $this->route('inboundEmail');
                $address = $email instanceof InboundEmail ? $email->from_email : '';
                if ($address === '' || User::query()->whereRaw('lower(email) = ?', [strtolower($address)])->exists()
                    || str_ends_with($address, '@'.strtolower((string) config('inbound.internal_domain')))) {
                    $fail('No se puede invitar a esa dirección.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'company_id' => 'empresa', 'role' => 'rol'];
    }
}
