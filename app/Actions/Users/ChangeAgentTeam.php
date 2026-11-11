<?php

/*
 * Cambiar el equipo (Soporte / Desarrollo y Diseño) de un agente. Solo el administrador (UserPolicy::changeTeam).
 * Cuándo tocarlo: si el equipo pasa a significar algo más. El equipo decide a quién avisa TicketNotifier y qué
 * departamentos puede ver y atender el agente (User::workableDepartments, R11/R13).
 *
 * Trampa (D-12, 2026-11-11): al cambiar de equipo se liberan los tickets no cerrados cuyo departamento el agente
 * YA NO puede atender (p. ej. de Soporte a Contabilidad: los de Soporte). Antes se dejaban asignados y quedaban
 * huérfanos: el agente dejaba de verlos y nadie los veía en «Sin asignar». Los que sí puede atender se conservan.
 */

namespace App\Actions\Users;

use App\Enums\Department;
use App\Enums\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChangeAgentTeam
{
    public function __construct(private readonly UnassignTickets $unassign) {}

    public function __invoke(User $actor, User $agent, Team $team): void
    {
        Gate::forUser($actor)->authorize('changeTeam', $agent);

        $from = $agent->team;
        if ($from === $team) {
            return;
        }

        // Una sola transacción: o cambia el equipo Y se liberan los tickets, o no cambia nada.
        DB::transaction(function () use ($actor, $agent, $team, $from): void {
            $agent->forceFill(['team' => $team])->save();

            // Con el equipo ya cambiado, lo que no esté en su lista de departamentos atendibles lo deja de llevar.
            $lost = array_values(array_diff(array_map(fn (Department $d) => $d->value, Department::cases()), $agent->workableDepartments()));
            $freed = $lost === [] ? 0 : ($this->unassign)($actor, $agent, 'team_changed', $lost);

            Audit::record('team_changed', "{$agent->email}: equipo de {$from?->label()} a {$team->label()}.", $actor, $agent, null, ['from' => $from?->value, 'to' => $team->value, 'tickets_released' => $freed]);
        });
    }
}
