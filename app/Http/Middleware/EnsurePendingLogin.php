<?php

/*
 * Protege las pantallas del segundo factor: solo se entra a ellas con un acceso «a medias» vigente
 * (correo y contraseña ya acertados, ver App\Support\PendingLogin). Si no lo hay, a la pantalla de acceso.
 * Cuándo tocarlo: al cambiar cómo se encadena el segundo factor con el acceso.
 */

namespace App\Http\Middleware;

use App\Support\PendingLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePendingLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = PendingLogin::user($request);

        if ($usuario === null) {
            return redirect()->route('login')->with('status', 'La verificación ha caducado. Vuelve a iniciar sesión.');
        }

        $request->attributes->set('pending_user', $usuario);

        return $next($request);
    }
}
