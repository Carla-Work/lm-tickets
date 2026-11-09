<?php

/*
 * Quién puede gestionar cuentas. Reglas R1, R8, R9, R18. Matriz: docs/plan/autorizacion.md.
 * Cuándo tocarlo: solo si la usuaria cambia quién crea o da de baja cuentas.
 *
 * Trampas:
 *  - NADIE invita con el rol de administrador: el rol solo se obtiene por TRASPASO (R9).
 *  - El administrador no puede darse de baja a sí mismo (R9: siempre hay uno activo).
 *  - Un administrador de empresa nunca toca nada de OTRA empresa (R1), ni a sí mismo al dar de baja.
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::CompanyAdmin);
    }

    public function view(User $user, User $target): bool
    {
        if ($user->is($target) || $user->hasRole(UserRole::Admin)) {
            return true;
        }

        return $user->hasRole(UserRole::CompanyAdmin) && $target->company_id !== null && $target->company_id === $user->company_id;
    }

    /**
     * R8, R18: ¿puede este usuario invitar a una cuenta con ESE rol (y en ESA empresa)?
     *  - Administrador: agentes, y el primer administrador de empresa de una empresa.
     *  - Administrador de empresa: usuarios y administradores de SU empresa.
     *  - Nadie invita administradores (se traspasa).
     */
    public function invite(User $user, UserRole $role, ?Company $company = null): bool
    {
        if ($user->hasRole(UserRole::Admin)) {
            return match ($role) {
                UserRole::Agent => $company === null,
                UserRole::CompanyAdmin => $company !== null,
                default => false,
            };
        }

        if ($user->hasRole(UserRole::CompanyAdmin)) {
            return $company !== null
                && $company->id === $user->company_id
                && in_array($role, [UserRole::CompanyUser, UserRole::CompanyAdmin], true);
        }

        return false;
    }

    /** Reenviar la invitación: puede quien podría haber creado esa cuenta, y solo si sigue sin aceptar. */
    public function resendInvitation(User $user, User $target): bool
    {
        if (! $target->isPendingInvitation() || $target->isDeactivated()) {
            return false;
        }

        return $this->invite($user, $target->getAttribute('role'), $target->company);
    }

    /** Dar de baja. R9: el administrador no puede darse de baja a sí mismo; antes traspasa el rol. */
    public function deactivate(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return false; // nadie se da de baja a sí mismo (R9 para el administrador; el resto, por prudencia)
        }

        if ($user->hasRole(UserRole::Admin)) {
            return true;
        }

        return $user->hasRole(UserRole::CompanyAdmin)
            && $target->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin)
            && $target->company_id === $user->company_id;
    }

    /** Cambiar usuario ↔ administrador de empresa. El administrador, en cualquiera; el de empresa, en la suya y no el propio. */
    public function changeCompanyRole(User $user, User $target): bool
    {
        if (! $target->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin)) {
            return false;
        }
        if ($user->hasRole(UserRole::Admin)) {
            return true;
        }

        return $user->hasRole(UserRole::CompanyAdmin) && ! $user->is($target) && $target->company_id === $user->company_id;
    }

    /** Cambiar el equipo de un agente. Solo el administrador. */
    public function changeTeam(User $user, User $target): bool
    {
        return $user->hasRole(UserRole::Admin) && $target->hasRole(UserRole::Agent);
    }

    /**
     * Traspasar el rol de administrador (R9): solo el administrador, y a un AGENTE activo que no sea él.
     * Es la única forma de que alguien llegue a ser administrador.
     */
    public function transferAdmin(User $user, User $target): bool
    {
        return $user->hasRole(UserRole::Admin)
            && ! $user->is($target)
            && $target->hasRole(UserRole::Agent)
            && $target->isOperational();
    }
}
