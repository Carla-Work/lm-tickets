<?php

/*
 * Página a la que llega cualquiera tras entrar: reparte a cada persona a su lista. Los CLIENTES van a
 * «Mis tickets»; agentes y administrador, a la «Bandeja».
 * Cuándo tocarlo: al cambiar a dónde aterriza cada rol (o al crear un panel de inicio real).
 */

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        // Cada uno aterriza en su lista: el equipo en la bandeja, los clientes en «Mis tickets».
        return redirect()->route($user->isInternal() ? 'inbox' : 'tickets.index');
    }
}
