<?php

/*
 * Mensaje del hilo: público (lo ve el cliente) o nota interna (solo el equipo).
 * Cuándo tocarlo: al cambiar el hilo. Regla R2.
 *
 * TRAMPA CRÍTICA (R2): todo lo que ve el cliente (pantallas, correos, avisos) debe usar
 * `scopePublic()`. Un listado sin filtrar le entrega las notas internas.
 */

namespace App\Models;

use App\Enums\MessageKind;
use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['body'])]
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['kind' => MessageKind::class, 'via_email' => 'boolean', 'emailed_at' => 'datetime'];
    }

    /**
     * Solo mensajes públicos. ÚNICA vía permitida para todo lo que ve el cliente (R2).
     *
     * @param  Builder<TicketMessage>  $query
     * @return Builder<TicketMessage>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('kind', MessageKind::Public->value);
    }

    /**
     * Los mensajes que este usuario puede ver (R2): el equipo ve todo; un CLIENTE solo los públicos.
     * Se usa SIEMPRE que se listan mensajes. Ojo: no comprueba que el usuario vea el TICKET; eso lo
     * hace Ticket::visibleTo / TicketPolicy::view. Se combinan.
     *
     * @param  Builder<TicketMessage>  $query
     * @return Builder<TicketMessage>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isInternal() && $user->isOperational() ? $query : $query->public();
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
