<?php

/*
 * Traspasar el rol de administrador (R9): el único administrador pasa a agente y un agente pasa a administrador.
 * Cuándo tocarlo: al cambiar cómo se cede el cargo. Es la ÚNICA forma de que alguien llegue a ser administrador.
 *
 * Trampas:
 *  - UNA transacción con ambas cuentas bloqueadas: nunca debe quedar sin administrador ni con dos. El orden
 *    importa por el índice único `users.admin_slot`: primero se degrada al antiguo, después se asciende al nuevo.
 *  - El antiguo pasa a agente y la base de datos exige que un agente tenga equipo: por eso el formulario lo pide.
 *  - Al antiguo se le borra el segundo factor (User::clearTwoFactor): ya no es administrador y el nuevo activará
 *    el suyo si quiere (R21). Se hace dentro de la transacción.
 *  - El nuevo administrador no lleva tickets (R10: el administrador no responde): se le quitan los que tuviera.
 *  - Las sesiones abiertas de ambos cambian de permisos en la siguiente petición: el rol se lee en cada una.
 */

namespace App\Actions\Users;

use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransferAdmin
{
    public function __construct(private readonly UnassignTickets $unassign) {}

    public function __invoke(User $actor, User $newAdmin, Team $formerAdminTeam): void
    {
        DB::transaction(function () use ($actor, $newAdmin, $formerAdminTeam): void {
            // Orden fijo de bloqueo (por id) para que dos traspasos simultáneos no se crucen.
            $locked = User::query()->whereIn('id', [$actor->id, $newAdmin->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $former = $locked[$actor->id];
            $incoming = $locked[$newAdmin->id];

            Gate::forUser($former)->authorize('transferAdmin', $incoming);

            $former->forceFill(['role' => UserRole::Agent, 'team' => $formerAdminTeam])->save();
            $incoming->forceFill(['role' => UserRole::Admin, 'team' => null])->save();
            $former->clearTwoFactor();
            ($this->unassign)($former, $incoming, 'role_transfer');

            Audit::record('admin_transferred', "Rol de administrador traspasado de {$former->email} a {$incoming->email}.", $former, $incoming);
        });
    }
}
