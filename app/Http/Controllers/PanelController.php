<?php

/*
 * Página a la que llega cualquiera tras entrar. Qué es: un PROVISIONAL del paso 2.
 * Cuándo tocarlo: en el paso 4 se sustituye por «Mis tickets» (cliente) y «Bandeja» (interno),
 * según los mockups validados. Hasta entonces solo demuestra que el acceso funciona.
 */

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('panel', ['usuario' => $request->user()]);
    }
}
