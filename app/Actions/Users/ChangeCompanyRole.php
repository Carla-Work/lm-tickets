<?php

/*
 * Cambiar a una persona de una empresa entre «usuario» y «administrador de empresa».
 * Cuándo tocarlo: si cambian los roles de cliente. Quién puede: UserPolicy::changeCompanyRole (el administrador de
 * la aplicación en cualquiera; el administrador de empresa solo en los de SU empresa y nunca en sí mismo, R1).
 *
 * Trampas:
 *  - Solo se aceptan los dos roles de cliente: por aquí NUNCA se llega a agente ni a administrador (R8/R9).
 *  - Se relee la cuenta con bloqueo: dos cambios simultáneos no se pisan.
 */

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeCompanyRole
{
    public function __invoke(User $actor, User $target, UserRole $role): void
    {
        if (! in_array($role, [UserRole::CompanyUser, UserRole::CompanyAdmin], true)) {
            throw ValidationException::withMessages(['role' => 'Ese rol no se puede asignar desde aquí.']);
        }

        DB::transaction(function () use ($actor, $target, $role): void {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('changeCompanyRole', $target);

            $from = $target->role;
            if ($from === $role) {
                return;
            }

            $target->forceFill(['role' => $role])->save();
            Audit::record('company_role_changed', "{$target->email}: de {$from->label()} a {$role->label()}.", $actor, $target, null, ['from' => $from->value, 'to' => $role->value]);
        });
    }
}
