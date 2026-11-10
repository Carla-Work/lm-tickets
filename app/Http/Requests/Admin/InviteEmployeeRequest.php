<?php

/*
 * Validación de «Nuevo empleado» (agente): nombre, correo único y equipo. El rol NO se recibe: solo se crean
 * agentes (el administrador se obtiene por traspaso, R9).
 * Cuándo tocarla: al cambiar el formulario. Autoriza ANTES de validar (UserPolicy::invite).
 */

namespace App\Http\Requests\Admin;

use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invite', [User::class, UserRole::Agent, null]) ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'team' => ['required', Rule::enum(Team::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'email' => 'correo', 'team' => 'equipo'];
    }
}
