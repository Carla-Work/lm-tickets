<?php

/*
 * Cambiar el equipo (Soporte / Desarrollo y Diseño) de un agente. Solo el administrador (UserPolicy::changeTeam).
 * Cuándo tocarlo: si el equipo pasa a significar algo más. Hoy solo decide a quién avisa TicketNotifier de los
 * tickets nuevos de cada departamento; los tickets ya asignados NO se mueven (cualquier agente puede llevar
 * cualquiera, R11).
 */

namespace App\Actions\Users;

use App\Enums\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

class ChangeAgentTeam
{
    public function __invoke(User $actor, User $agent, Team $team): void
    {
        Gate::forUser($actor)->authorize('changeTeam', $agent);

        $from = $agent->team;
        if ($from === $team) {
            return;
        }

        $agent->forceFill(['team' => $team])->save();
        Audit::record('team_changed', "{$agent->email}: equipo de {$from?->label()} a {$team->label()}.", $actor, $agent, null, ['from' => $from?->value, 'to' => $team->value]);
    }
}
