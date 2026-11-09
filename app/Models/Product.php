<?php

/*
 * Producto o servicio del catálogo (oneCOP, oneFact, Pool de mantenimiento…).
 * Cuándo tocarlo: al cambiar el catálogo. Lo edita el administrador.
 *
 * Trampa (R19): un producto asignado a una empresa o usado en un ticket NO se borra, se desactiva
 * (`active`). La base de datos lo impide con claves foráneas RESTRICT.
 */

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    // Sin PHPDoc a propósito: un `@return array<string, string>` borra a Larastan el tipo de las enumeraciones.
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }
}
