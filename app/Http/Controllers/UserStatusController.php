<?php

/*
 * Dar de baja o reactivar una cuenta (empleado desde Administración › Usuarios; usuario de empresa desde
 * «Mi empresa»). Cuándo tocarlo: nunca para cambiar QUIÉN puede: eso es UserPolicy::deactivate y su test.
 *
 * Trampas: la policy ya impide darse de baja a uno mismo (R9) y que un administrador de empresa toque a otra
 * empresa (R1). Aquí solo se vuelve a la pantalla de origen (`back()`), nunca a una URL recibida.
 */

namespace App\Http\Controllers;

use App\Actions\Users\SetUserActive;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserStatusController extends Controller
{
    public function deactivate(Request $request, User $user, SetUserActive $set): RedirectResponse
    {
        Gate::authorize('deactivate', $user);
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $set->deactivate($actor, $user);

        return back()->with('status', "Cuenta de {$user->name} dada de baja.");
    }

    public function reactivate(Request $request, User $user, SetUserActive $set): RedirectResponse
    {
        Gate::authorize('deactivate', $user);
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        $set->reactivate($actor, $user);

        return back()->with('status', "Cuenta de {$user->name} reactivada.");
    }
}
