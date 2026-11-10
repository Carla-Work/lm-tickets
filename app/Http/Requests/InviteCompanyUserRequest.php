<?php

/*
 * Validación de «Añadir un usuario» en «Mi empresa»: nombre, correo único y rol (usuario o administrador de empresa).
 * Cuándo tocarla: al cambiar el formulario. La EMPRESA no viaja en el formulario: es siempre la de quien invita (R1).
 * Autoriza ANTES de validar; el controlador vuelve a comprobar con el rol concreto elegido (UserPolicy::invite).
 */

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteCompanyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->company !== null && $user->can('invite', [User::class, UserRole::CompanyUser, $user->company]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in([UserRole::CompanyUser->value, UserRole::CompanyAdmin->value])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'email' => 'correo', 'role' => 'rol'];
    }
}
