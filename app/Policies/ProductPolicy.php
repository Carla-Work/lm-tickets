<?php

/*
 * Quién gestiona el catálogo de productos (R7, R19). Matriz: docs/plan/autorizacion.md.
 * Cuándo tocarlo: solo si la usuaria cambia quién edita el catálogo.
 *
 * Trampa (R19): un producto en uso NO se borra, se desactiva. `delete` lo niega con un mensaje claro;
 * la base de datos lo impide de todos modos con claves foráneas RESTRICT.
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasRole(UserRole::Admin);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasRole(UserRole::Admin)
            && ! $product->companies()->exists()
            && ! Ticket::where('product_id', $product->id)->exists();
    }
}
