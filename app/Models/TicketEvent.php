<?php

/*
 * Evento del historial de un ticket (R5): quién cambió qué y cuándo.
 * Cuándo tocarlo: al registrar un cambio nuevo (ver TicketEventType).
 *
 * Trampa: el historial SOLO SE AÑADE. No tiene `updated_at` y no debe haber código que lo edite
 * o lo borre; si algo se corrige, se añade otro evento.
 */

namespace App\Models;

use App\Enums\TicketEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    public const UPDATED_AT = null; // solo se añade (R5)

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['type' => TicketEventType::class, 'data' => 'array', 'visible_to_customer' => 'boolean', 'created_at' => 'datetime'];
    }

    /**
     * Los eventos que este usuario puede ver (R2, R5): el equipo ve todo el historial; un CLIENTE solo
     * los marcados `visible_to_customer` (p. ej. no ve las reasignaciones entre agentes).
     *
     * @param  Builder<TicketEvent>  $query
     * @return Builder<TicketEvent>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isInternal() && $user->isOperational() ? $query : $query->where('visible_to_customer', true);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Quien lo hizo; nulo = el sistema. @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
