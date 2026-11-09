<?php

/*
 * Quién puede ver y administrar empresas. Reglas R1, R7, R8. Matriz: docs/plan/autorizacion.md.
 * Cuándo tocarlo: solo si la usuaria cambia quién administra empresas.
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    /** El administrador ve cualquiera; un cliente, solo la SUYA («Mi empresa», solo lectura). R1. */
    public function view(User $user, Company $company): bool
    {
        if ($user->hasRole(UserRole::Admin)) {
            return true;
        }

        return $user->hasRole(UserRole::CompanyUser, UserRole::CompanyAdmin) && $user->company_id === $company->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function deactivate(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    /** R7: SOLO el administrador asigna productos a una empresa. El administrador de empresa los ve, no los cambia. */
    public function assignProducts(User $user, Company $company): bool
    {
        return $user->hasRole(UserRole::Admin);
    }
}
