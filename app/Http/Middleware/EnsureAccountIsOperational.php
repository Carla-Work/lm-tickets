<?php

/*
 * Cierra la sesión de quien YA NO puede usar la aplicación: cuenta dada de baja, o cliente cuya empresa
 * se ha dado de baja. Cuándo tocarlo: nunca para «dejar pasar»; si molesta, el problema es la baja.
 *
 * Por qué hace falta si ya existe Gate::before: ese filtro solo protege lo que pasa por una policy. Una
 * ruta que no use policies (el panel, por ejemplo) seguiría abierta para alguien dado de baja que
 * conservara una sesión iniciada antes. Este middleware cierra esa puerta en CADA petición.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsOperational
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isOperational()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
