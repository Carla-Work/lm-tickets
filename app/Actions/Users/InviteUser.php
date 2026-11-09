<?php

/*
 * Invita a una persona a la aplicación (R18: no hay registro libre; toda cuenta nace así).
 * Cuándo tocarlo: al cambiar cómo nacen las cuentas. Lo llamarán las pantallas del administrador
 * (alta de empresa, empleados) y del administrador de empresa (sus usuarios).
 *
 * Cómo funciona: crea la cuenta SIN contraseña utilizable (una aleatoria que nadie conoce) y con
 * `activated_at` nulo, y envía un enlace para elegirla. Hasta que la persona lo use, no puede entrar.
 *
 * Trampas:
 *  - Quién puede invitar a quién (R8) lo decide la POLICY del paso 3, no esta clase: aquí se asume
 *    que el permiso ya se comprobó.
 *  - El rol, la empresa y el equipo se asignan aquí a mano (no están en #[Fillable], a propósito).
 *  - Las combinaciones imposibles (agente con empresa, cliente sin empresa…) las rechaza la base de
 *    datos con una excepción: no se duplica esa lógica aquí.
 */

namespace App\Actions\Users;

use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class InviteUser
{
    public function handle(string $name, string $email, UserRole $role, ?Company $company = null, ?Team $team = null): User
    {
        $user = new User;
        $user->fill(['name' => $name, 'email' => $email]);
        $user->forceFill([
            'role' => $role,
            'company_id' => $company?->id,
            'team' => $team,
            'password' => Hash::make(Str::random(64)), // nadie la conoce: obliga a usar la invitación
            'activated_at' => null,
        ])->save();

        $this->send($user);

        return $user;
    }

    /** Envía (o reenvía) la invitación a una cuenta que aún no la ha usado. */
    public function send(User $user): void
    {
        $token = Password::broker('invitations')->createToken($user);
        $user->notify(new InvitationNotification($token));
    }
}
