<?php

/*
 * Acceso «a medias» de quien ya ha acertado correo y contraseña pero aún debe pasar el segundo factor.
 * Cuándo tocarlo: al cambiar cómo se encadena el segundo factor con el acceso (R21).
 *
 * Idea: hasta pasar el segundo factor la persona NO está autenticada (Auth::check() es falso). Solo se
 * recuerda en la sesión QUIÉN es, durante unos minutos. Así nada protegido se abre antes de tiempo.
 *
 * Trampas:
 *  - Caduca a los 10 minutos DESDE EL INICIO (no se renueva): quien deja la pantalla abierta debe
 *    volver a poner la contraseña.
 *  - Se vuelve a comprobar que la cuenta sigue activa cada vez que se lee (podrían haberla dado de baja).
 *  - Se regenera la sesión al empezar (evita la fijación de sesión).
 */

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

final class PendingLogin
{
    private const KEY = 'login.pending';

    private const CODES_KEY = 'two_factor.codes';

    public static function start(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::KEY, ['id' => $user->id, 'at' => now()->timestamp]);
    }

    public static function user(Request $request): ?User
    {
        $datos = $request->session()->get(self::KEY);
        if (! is_array($datos)) {
            return null;
        }

        if (now()->timestamp - (int) $datos['at'] > (int) config('access.two_factor.pending_seconds')) {
            self::forget($request);

            return null;
        }

        $usuario = User::find($datos['id']);

        return $usuario !== null && ! $usuario->isDeactivated() ? $usuario : null;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([self::KEY, self::CODES_KEY]);
    }
}
