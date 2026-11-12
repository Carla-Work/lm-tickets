<?php

/*
 * Quién ve y decide sobre el correo entrante en cuarentena, y quién gestiona palabras clave y dominios: SOLO el administrador.
 * Matriz: docs/plan/autorizacion.md. Cuándo tocarla: solo si la usuaria cambia quién revisa el correo (cambiar la matriz Y el test).
 *
 * Por qué solo el administrador: la cuarentena contiene correo de remitentes DESCONOCIDOS (datos personales sin cuenta) y de los tres buzones, también
 * comercial@ (Contabilidad, R13): ningún agente de otro departamento debe verlo. El administrador decide; no responde al remitente (R10).
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InboundEmail;
use App\Models\User;

class InboundEmailPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function view(User $user, InboundEmail $email): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    /** Marcar spam, quitar la marca, invitar al remitente o crear el ticket. Solo en cuarentena (pending/spam). */
    public function decide(User $user, InboundEmail $email): bool
    {
        return $user->hasRole(UserRole::Admin) && in_array($email->status, [InboundEmail::PENDING, InboundEmail::SPAM], true);
    }

    /** Palabras clave de producto, palabras de incidencia y dominios de empresa (clase, no un correo concreto). */
    public function manageSettings(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }
}
