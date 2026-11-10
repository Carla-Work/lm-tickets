<?php

/*
 * Crear contraseña desde un enlace del correo. Sirve para DOS casos con la misma pantalla:
 *   · recuperar la contraseña  (broker «users», enlace de 60 minutos)
 *   · ACTIVAR una cuenta invitada (broker «invitations», enlace de 7 días)  — R18
 * Reglas: R17 (mensaje único de error), contraseña de 12 caracteres como mínimo (decidido).
 * Cuándo tocarlo: al cambiar cómo se crea la contraseña.
 *
 * Trampas:
 *  - TODO fallo (enlace caducado, ya usado, correo que no coincide, cuenta dada de baja) da el
 *    mismo mensaje: no se explica cuál fue, para no revelar nada (R17).
 *  - El enlace es de un solo uso: al cambiar la contraseña el token se borra.
 *  - Un enlace de invitación NO sirve en la ruta de recuperación, ni uno de recuperación en la de
 *    invitación: cada ruta filtra por el estado de la cuenta (ver `estado_de_la_cuenta`).
 *  - Al guardar la contraseña se marca la cuenta como activada (`activated_at`): es lo que le
 *    permite entrar. Es el ÚNICO sitio que activa cuentas.
 *  - No se inicia sesión sola tras guardarla: se lleva a «Iniciar sesión» (decidido en el mockup).
 */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token, string $broker): View
    {
        return view('acceso.nueva-contrasena', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'broker' => $broker,
        ]);
    }

    public function store(Request $request, string $broker): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min((int) config('access.password.min_length'))],
        ]);

        $estado = Password::broker($broker)->reset(
            [
                'email' => $datos['email'],
                'password' => $datos['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $datos['token'],
                // Cada ruta acepta SOLO el tipo de cuenta que le corresponde: la invitación, cuentas aún sin
                // activar; la recuperación, cuentas ya activadas. Ambos tipos de enlace comparten tabla de
                // tokens, y sin esta condición uno serviría en la ruta del otro.
                'estado_de_la_cuenta' => $broker === 'invitations'
                    ? fn (Builder $consulta) => $consulta->whereNull('activated_at')->whereNull('deactivated_at')
                    : fn (Builder $consulta) => $consulta->whereNotNull('activated_at')->whereNull('deactivated_at'),
            ],
            function (User $usuario, string $contrasena) use ($request): void {
                $wasActivated = $usuario->activated_at !== null;
                $usuario->forceFill([
                    'password' => Hash::make($contrasena),
                    'remember_token' => Str::random(60),
                    'activated_at' => $usuario->activated_at ?? now(), // activa la cuenta invitada
                ])->save();

                event(new PasswordReset($usuario));
                Audit::record($wasActivated ? 'password_reset' : 'activated',
                    $wasActivated ? 'Ha cambiado su contraseña con el enlace de recuperación.' : 'Ha activado su cuenta eligiendo su contraseña.',
                    $usuario, ip: $request->ip());
            },
        );

        if ($estado !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['password' => trans('passwords.invalid_link')]);
        }

        return redirect()->route('login')->with('status', trans('passwords.saved'));
    }
}
