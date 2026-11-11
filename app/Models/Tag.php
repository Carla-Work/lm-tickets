<?php

/*
 * Etiqueta INTERNA de un ticket (catálogo cerrado que gestiona el administrador). Cuándo tocarla: al cambiar el catálogo.
 *
 * Trampas:
 *  - Las ve SOLO el equipo: ninguna vista, correo ni aviso del cliente las pinta (lo vigila TicketPriorityTagsTest).
 *  - Una etiqueta en uso NO se borra, se desactiva (`active`, como productos, R19): deja de ofrecerse, pero los tickets
 *    que ya la llevan la conservan y se puede filtrar por ella. La base lo impide con claves foráneas RESTRICT.
 */

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'active'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /** Etiquetas máximas por ticket (decidido 2026-11-11). */
    public const MAX_PER_TICKET = 5;

    /** Longitud máxima del nombre (la columna `tags.name` es de 40). */
    public const NAME_MAX = 40;

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_tag');
    }
}
