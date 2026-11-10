<?php

/*
 * Inicio y cierre de sesión. Reglas: R17 (errores genéricos), R20 (bloqueo a los 5 intentos).
 * Cuándo tocarlo: al cambiar cómo se entra.
 *
 * Trampas:
 *  - TODOS los motivos de rechazo dan el MISMO mensaje: contraseña mala, correo inexistente, cuenta
 *    desactivada, invitación sin aceptar o empresa dada de baja. Si se distinguieran, se podría
 *    averiguar qué correos existen (R17).
 *  - El bloqueo se comprueba ANTES de mirar la contraseña (aunque sea la buena).
 *  - Tras entrar se regenera la sesión (evita la fijación de sesión).
 *  - El administrador (R21) no queda autenticado aquí: sigue en TwoFactorController. Hasta pasar el
 *    segundo factor, Auth::check() es falso.
 */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LoginThrottle;
use App\Support\PendingLogin;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('acceso.entrar');
    }

    public function store(Request $request, LoginThrottle $throttle): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $clave = LoginThrottle::key($datos['email'], (string) $request->ip());

        if ($throttle->isLocked($clave)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['minutes' => (int) ceil($throttle->secondsLeft($clave) / 60)]),
            ]);
        }

        // Solo cuentas activadas y no dadas de baja. Si no cumple, es como si no existiera.
        $credenciales = [
            'email' => $datos['email'],
            'password' => $datos['password'],
            fn (Builder $consulta) => $consulta->whereNotNull('activated_at')->whereNull('deactivated_at'),
        ];
        $proveedor = Auth::guard()->getProvider();
        $usuario = $proveedor->retrieveByCredentials($credenciales);
        $entra = $usuario instanceof User && $proveedor->validateCredentials($usuario, $credenciales);

        // Un cliente de una empresa dada de baja tampoco entra (aunque su cuenta siga activa).
        if ($entra && ! $this->companyAllowsLogin($usuario)) {
            $entra = false;
        }

        if (! $entra) {
            $throttle->fail($clave);
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        // R21 (opcional desde 2026-11-09): el administrador que HA ACTIVADO el segundo factor NO queda
        // autenticado todavía; debe pasar el código. Sin 2FA activado entra como los demás. Y NO se borran
        // sus fallos: si se borraran aquí, quien conoce la contraseña reiniciaría el contador a voluntad
        // y podría probar códigos sin límite (ver TwoFactorController).
        if ($usuario->requiresTwoFactor()) {
            PendingLogin::start($request, $usuario);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($usuario);
        $throttle->clear($clave);
        $request->session()->regenerate();

        return redirect()->intended(route('panel'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function companyAllowsLogin(?User $user): bool
    {
        return $user !== null && ($user->company === null || $user->company->isActive());
    }
}
