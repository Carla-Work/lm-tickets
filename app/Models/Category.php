<?php

/*
 * Categoría de incidencia, colgada de un departamento. La edita el administrador.
 * Cuándo tocarlo: si cambia cómo se clasifican los tickets.
 */

namespace App\Models;

use App\Enums\Department;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['department' => Department::class, 'active' => 'boolean'];
    }
}
