<?php

/*
 * «He olvidado mi contraseña»: pedir el enlace. Regla R17.
 * Cuándo tocarlo: al cambiar cómo se recupera el acceso.
 *
 * TRAMPA R17: la respuesta es SIEMPRE la misma, exista o no el correo. Por eso se IGNORA el estado
 * que devuelve Laravel: su estado «throttled» («ya te enviamos uno hace poco») solo ocurre con
 * correos que existen y lo delataría. Tampoco se envía nada a cuentas dadas de baja o sin activar
 * (las no activadas deben usar su invitación, no la recuperación).
 */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('acceso.recuperar');
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate(['email' => ['required', 'email']]);

        Password::broker('users')->sendResetLink([
            'email' => $datos['email'],
            'cuenta_utilizable' => fn (Builder $consulta) => $consulta->whereNotNull('activated_at')->whereNull('deactivated_at'),
        ]);

        // Se ignora el resultado a propósito (ver la cabecera).
        return redirect()->route('password.sent');
    }

    public function sent(): View
    {
        return view('acceso.recuperar-enviado');
    }
}
