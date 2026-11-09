<?php

/*
 * El ticket. Ver docs/plan/modelo-de-datos.md, sección «Tickets».
 * Cuándo tocarlo: al cambiar sus datos o su ciclo de vida. Reglas: R1, R4, R6, R13, R14, R15, R16.
 *
 * Trampas:
 *  - El número (`2026-0312`) se reparte al crear (evento `creating`) con TicketSequence; no se
 *    asigna a mano.
 *  - Casi nada es asignable en masa: lo fija el código de servidor, no un formulario.
 *  - La descripción es el PRIMER mensaje del hilo, no una columna.
 *  - El equipo que lo atiende se deduce del departamento (Department::team()), no se guarda.
 */

namespace App\Models;

use App\Enums\Department;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $number Número legible, p. ej. «2026-0312» (accesor `number()`).
 */
#[Fillable(['subject'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // El número lo reparte un único sitio. Si ya viene informado (p. ej. un test), se respeta.
        static::creating(function (Ticket $ticket): void {
            // getAttribute(): antes de crear, estas columnas SÍ son nulas aunque en la base no lo sean.
            if ($ticket->getAttribute('year') === null || $ticket->getAttribute('sequence') === null) {
                $ticket->year = (int) now()->format('Y');
                $ticket->sequence = TicketSequence::next($ticket->year);
            }
        });
    }

    // Sin PHPDoc a propósito: un `@return array<string, string>` borra a Larastan el tipo de las enumeraciones.
    protected function casts(): array
    {
        return [
            'department' => Department::class,
            'status' => TicketStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Número legible: `2026-0312`. @return Attribute<string, never> */
    protected function number(): Attribute
    {
        return Attribute::get(fn (): string => sprintf('%d-%04d', $this->year, $this->sequence));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Ticket del que este «se clonó» (nadie reabre, R15). @return BelongsTo<Ticket, $this> */
    public function relatedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'related_ticket_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
