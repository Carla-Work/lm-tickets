<?php

/*
 * Dar de baja o reactivar una cuenta. Nunca se borra (las claves foráneas son RESTRICT).
 * Cuándo tocarlo: al cambiar qué implica la baja de una persona. Lo usan Administración › Usuarios (empleados)
 * y «Mi empresa» (usuarios de una empresa): quién puede a quién lo decide UserPolicy::deactivate.
 *
 * Trampas:
 *  - La baja corta el acceso en la SIGUIENTE petición (Gate::before + middleware `operational`), también con
 *    sesión abierta.
 *  - R9: el administrador no se da de baja (la policy lo niega); se traspasa el rol antes.
 *  - Una cuenta aún sin aceptar la invitación también puede darse de baja; reactivar NO la activa (sigue
 *    necesitando su invitación, R18).
 *  - Al dar de baja a un agente se le quitan los tickets que lleva (UnassignTickets).
 */

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SetUserActive
{
    public function __construct(private readonly UnassignTickets $unassign) {}

    public function deactivate(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('deactivate', $target);

        DB::transaction(function () use ($actor, $target): void {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($target->isDeactivated()) {
                return;
            }

            $target->forceFill(['deactivated_at' => now()])->save();
            $released = $target->hasRole(UserRole::Agent) ? ($this->unassign)($actor, $target, 'user_deactivated') : 0;
            Audit::record('user_deactivated', "Cuenta de {$target->email} dada de baja.", $actor, $target, null, ['tickets_released' => $released]);
        });
    }

    public function reactivate(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('deactivate', $target);

        if ($target->isDeactivated()) {
            $target->forceFill(['deactivated_at' => null])->save();
            Audit::record('user_reactivated', "Cuenta de {$target->email} reactivada.", $actor, $target);
        }
    }
}
