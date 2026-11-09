<?php

/*
 * Quién gestiona las categorías. Matriz: docs/plan/autorizacion.md.
 * Cuándo tocarlo: solo si la usuaria cambia quién edita categorías.
 * Una categoría usada en algún ticket no se borra (se desactiva), igual que los productos.
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->hasRole(UserRole::Admin) && ! Ticket::where('category_id', $category->id)->exists();
    }
}
