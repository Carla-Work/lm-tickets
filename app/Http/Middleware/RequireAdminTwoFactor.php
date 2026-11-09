<?php

/*
 * Red de seguridad de R21: un administrador SIN el segundo factor activado no puede usar la aplicación.
 * Cuándo tocarlo: nunca para «dejar pasar»; si molesta, el problema es otro (p. ej. al traspasar el rol).
 *
 * Por qué hace falta si el acceso ya lo exige: una persona puede ser ascendida a administrador
 * MIENTRAS tiene la sesión abierta (traspaso del rol, R9). Esa sesión nació sin segundo factor y, sin
 * este filtro, seguiría valiendo. Se le cierra y debe volver a entrar, momento en que se le pide activarlo.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario !== null && $usuario->requiresTwoFactor() && ! $usuario->hasTwoFactorEnabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Para seguir como administrador, vuelve a entrar y activa la verificación en dos pasos.');
        }

        return $next($request);
    }
}
