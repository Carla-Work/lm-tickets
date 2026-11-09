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

    // Sin PHPDoc a propósito: un `@return array<string, string>` borra a Larastan el tipo de las enumeraciones.
    protected function casts(): array
    {
        return ['kind' => MessageKind::class];
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
