<?php

/*
 * Dar de baja o reactivar una empresa. Nunca se borra (los tickets se conservan; plan de conservación).
 * Cuándo tocarlo: al cambiar qué implica la baja.
 *
 * Trampa: la baja corta el acceso de TODOS sus usuarios sin tocar sus cuentas: `User::isOperational()` mira
 * la empresa en cada petición y `Gate::before` lo hace cumplir, así que una sesión abierta cae en la siguiente
 * petición. Reactivar la empresa devuelve el acceso a quien estuviera activo.
 */

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

class SetCompanyActive
{
    public function deactivate(User $actor, Company $company): void
    {
        Gate::forUser($actor)->authorize('deactivate', $company);

        if ($company->isActive()) {
            $company->forceFill(['deactivated_at' => now()])->save();
            Audit::record('company_deactivated', "Empresa «{$company->name}» dada de baja.", $actor, null, null, ['company_id' => $company->id]);
        }
    }

    public function reactivate(User $actor, Company $company): void
    {
        Gate::forUser($actor)->authorize('deactivate', $company);

        if (! $company->isActive()) {
            $company->forceFill(['deactivated_at' => null])->save();
            Audit::record('company_reactivated', "Empresa «{$company->name}» reactivada.", $actor, null, null, ['company_id' => $company->id]);
        }
    }
}
