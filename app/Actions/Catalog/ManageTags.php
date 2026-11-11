<?php

/*
 * Altas, cambios de nombre y activación/desactivación de etiquetas internas (solo el administrador).
 * Cuándo tocarlo: al cambiar qué se puede editar. Pantalla: Administración › Etiquetas.
 *
 * Trampas:
 *  - NO existe borrado (como productos, R19): una etiqueta se DESACTIVA. Desactivada deja de ofrecerse en los tickets,
 *    pero los que ya la llevan la conservan y se puede filtrar por ella.
 *  - Renombrar cambia el nombre en TODOS los tickets que la llevan (es la misma etiqueta). El historial de cada ticket
 *    guarda el nombre que tenía en ese momento, a propósito.
 *  - Cada método autoriza con la policy aunque el controlador ya lo haga (dos capas).
 */

namespace App\Actions\Catalog;

use App\Models\Tag;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

class ManageTags
{
    public function create(User $actor, string $name): Tag
    {
        Gate::forUser($actor)->authorize('create', Tag::class);

        $tag = new Tag;
        $tag->fill(['name' => $name, 'active' => true])->save();
        Audit::record('tag_created', "Etiqueta «{$tag->name}» creada.", $actor, null, null, ['tag_id' => $tag->id]);

        return $tag;
    }

    public function rename(User $actor, Tag $tag, string $name): void
    {
        Gate::forUser($actor)->authorize('update', $tag);

        $before = $tag->name;
        $tag->fill(['name' => $name]);
        if ($tag->isDirty()) {
            $tag->save();
            Audit::record('tag_renamed', "Etiqueta «{$before}» renombrada a «{$tag->name}».", $actor, null, null, ['tag_id' => $tag->id]);
        }
    }

    public function setActive(User $actor, Tag $tag, bool $active): void
    {
        Gate::forUser($actor)->authorize('update', $tag);

        if ($tag->active !== $active) {
            $tag->fill(['active' => $active])->save();
            Audit::record('tag_status_changed', "Etiqueta «{$tag->name}» ".($active ? 'activada' : 'desactivada').'.', $actor, null, null, ['tag_id' => $tag->id, 'active' => $active]);
        }
    }
}
