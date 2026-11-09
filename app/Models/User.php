<?php

/*
 * Cuenta de acceso: cliente (de una empresa) o empleado de Ling Marco.
 * Cuándo tocarlo: al cambiar datos de la cuenta o el modelo de roles (ver UserRole y la migración
 * add_role_company_and_team_to_users_table, que lleva las restricciones).
 *
 * Trampa: `role`, `company_id` y `team` NO están en #[Fillable] a propósito. Si un formulario
 * pudiera rellenarlos, cualquiera podría hacerse administrador. Los fija el código de servidor.
 */

namespace App\Models;

use App\Enums\Team;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Sin PHPDoc a propósito: un `@return array<string, string>` borra a Larastan el tipo de las enumeraciones.
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'team' => Team::class,
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Tickets que abrió esta persona. @return HasMany<Ticket, $this> */
    public function requestedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
    }

    /** Tickets que lleva esta persona. @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    /** Cuenta dada de baja: no puede acceder. */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** Invitada pero todavía sin contraseña elegida (R18). */
    public function isPendingInvitation(): bool
    {
        return $this->activated_at === null;
    }
}
