<?php

/*
 * Reenviar la invitación a una cuenta que aún no la ha aceptado (R18).
 * Cuándo tocarlo: al cambiar quién puede reenviar (se cambia UserPolicy::resendInvitation y su test, no esto).
 * Lo usan las pantallas de Empresas (administrador), Usuarios (administrador) y Mi empresa (administrador de empresa).
 *
 * Trampa: la policy comprueba que la cuenta sigue SIN aceptar y no está de baja; una cuenta activa no recibe
 * un enlace nuevo (sería una vía para restablecer su contraseña saltándose la recuperación, R17).
 */

namespace App\Http\Controllers;

use App\Actions\Users\InviteUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    public function resend(User $user, InviteUser $invite): RedirectResponse
    {
        Gate::authorize('resendInvitation', $user);

        $invite->send($user);

        return back()->with('status', "Invitación reenviada a {$user->email}.");
    }
}
