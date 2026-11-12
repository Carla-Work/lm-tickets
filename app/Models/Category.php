<?php

/*
 * Categoría de incidencia, colgada de un departamento. La edita el administrador.
 * Cuándo tocarlo: si cambia cómo se clasifican los tickets.
 */

namespace App\Models;

use App\Enums\Department;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Categorías que se ofrecen al abrir o mover un ticket: activas y NO la «por defecto» del correo entrante (`is_default`, «Sin clasificar»),
     * que solo asigna ProcessIncomingEmail. Úsala en cualquier selector o validación de categoría de un formulario.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeOffered($query)
    {
        return $query->where('active', true)->where('is_default', false);
    }

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['department' => Department::class, 'active' => 'boolean', 'is_default' => 'boolean'];
    }
}
