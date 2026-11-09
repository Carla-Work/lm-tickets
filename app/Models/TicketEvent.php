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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    public const UPDATED_AT = null; // solo se añade (R5)

    // Sin PHPDoc a propósito: un `@return array<string, string>` borra a Larastan el tipo de las enumeraciones.
    protected function casts(): array
    {
        return ['type' => TicketEventType::class, 'data' => 'array', 'visible_to_customer' => 'boolean', 'created_at' => 'datetime'];
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
