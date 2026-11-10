<?php

/*
 * Página a la que llega cualquiera tras entrar. Los CLIENTES van a «Mis tickets».
 * Cuándo tocarlo: en la entrega 4b, cuando exista la «Bandeja» del equipo (mockup bandeja-a): hasta
 * entonces los agentes y el administrador ven aún la página provisional del paso 2.
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

        if ($user !== null && ! $user->isInternal()) {
            return redirect()->route('tickets.index');
        }

        return view('panel', ['usuario' => $user]);
    }
}
