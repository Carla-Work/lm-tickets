<?php

/*
 * Quién gestiona el catálogo de etiquetas: solo el administrador (como productos y categorías). Matriz:
 * docs/plan/autorizacion.md. Cuándo tocarlo: solo si la usuaria cambia quién edita el catálogo.
 * No hay `delete`: una etiqueta se desactiva, nunca se borra.
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasRole(UserRole::Admin);
    }
}
