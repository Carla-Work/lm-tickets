<?php

/*
 * Valoración que el cliente hace de la atención de un ticket (1 a 5 y un comentario opcional). Plan:
 * docs/plan/p1-valoracion-atencion.md. Cuándo tocarla: al cambiar qué se guarda.
 *
 * Trampas: se crea UNA vez y no se edita (sin `updated_at`; la clave única de `ticket_id` lo impide en la base). Se
 * crea solo con RateTicket, que comprueba quién y cuándo. El comentario es texto libre: se pinta SIEMPRE escapado.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRating extends Model
{
    public const UPDATED_AT = null; // solo se añade

    public const COMMENT_MAX = 500;

    /** Textos de cada puntuación. */
    public const LABELS = [1 => 'Muy mala', 2 => 'Mala', 3 => 'Normal', 4 => 'Buena', 5 => 'Excelente'];

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
    protected function casts(): array
    {
        return ['score' => 'integer', 'created_at' => 'datetime'];
    }

    /** «★★★★☆» para una puntuación. */
    public static function stars(int $score): string
    {
        return str_repeat('★', $score).str_repeat('☆', 5 - $score);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
