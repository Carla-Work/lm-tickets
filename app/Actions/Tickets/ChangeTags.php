<?php

/*
 * Poner o quitar etiquetas INTERNAS de un ticket (P1, R5). Cuándo tocarla: al cambiar las reglas de etiquetado.
 *
 * Trampas:
 *  - Se recibe la lista COMPLETA de etiquetas que debe llevar (lo que marcó el formulario), no un añadir/quitar:
 *    el formulario son casillas y así dos agentes a la vez no se pisan a ciegas (gana el último, y queda el evento).
 *  - Solo se puede AÑADIR una etiqueta activa; las que el ticket ya lleva se pueden conservar aunque se hayan
 *    desactivado después (desactivar no quita nada a nadie). Tope de `Tag::MAX_PER_TICKET`.
 *  - Sin cambios reales no se crea evento. El evento es INTERNO y no avisa a nadie; no toca `last_activity_at` (ver
 *    ChangePriority: mismos motivos).
 *  - Dos capas de permiso: el controlador y aquí, con el ticket releído y bloqueado.
 */

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeTags
{
    /**
     * @param  list<int>  $tagIds  las etiquetas que debe llevar el ticket al terminar
     * @return bool si hubo algún cambio
     */
    public function __invoke(User $actor, Ticket $ticket, array $tagIds): bool
    {
        return DB::transaction(function () use ($actor, $ticket, $tagIds): bool {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            Gate::forUser($actor)->authorize('changeTags', $ticket);

            $wanted = array_values(array_unique($tagIds));
            if (count($wanted) > Tag::MAX_PER_TICKET) {
                throw ValidationException::withMessages(['etiquetas' => 'Un ticket lleva como máximo '.Tag::MAX_PER_TICKET.' etiquetas.']);
            }

            // Las que lleva ahora (consulta directa: así Larastan conoce el tipo de la colección).
            $current = Tag::query()->whereIn('id', DB::table('ticket_tag')->where('ticket_id', $ticket->id)->select('tag_id'))->get();
            $currentIds = $current->pluck('id')->all();
            $tags = Tag::query()->whereIn('id', $wanted)->get();

            // Existentes, y activas salvo que el ticket ya las llevara.
            $invalid = $tags->count() !== count($wanted) || $tags->contains(fn (Tag $t) => ! $t->active && ! in_array($t->id, $currentIds, true));
            if ($invalid) {
                throw ValidationException::withMessages(['etiquetas' => 'Alguna etiqueta no existe o está desactivada.']);
            }

            $added = $tags->reject(fn (Tag $t) => in_array($t->id, $currentIds, true));
            $removed = $current->reject(fn (Tag $t) => in_array($t->id, $wanted, true));
            if ($added->isEmpty() && $removed->isEmpty()) {
                return false;
            }

            $ticket->tags()->sync($wanted);

            $event = new TicketEvent;
            $event->ticket_id = $ticket->id;
            $event->actor_id = $actor->id;
            $event->type = TicketEventType::TagsChanged;
            $event->data = [
                'added' => $added->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
                'removed' => $removed->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            ];
            $event->visible_to_customer = false;
            $event->save();

            return true;
        });
    }
}
