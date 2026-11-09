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
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
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
            // La clave del segundo factor se guarda CIFRADA; los códigos de recuperación, con hash (ver la migración).
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    /**
     * Correo de «he olvidado mi contraseña» propio (en español, con la caducidad de config/auth.php).
     * Sustituye al de Laravel, que sale en inglés.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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

    /** R21: solo el administrador entra con segundo factor. */
    public function requiresTwoFactor(): bool
    {
        // getAttribute(): dentro del propio modelo Larastan ve `role` como string (el cast a enumeración
        // solo lo reconoce desde fuera); el valor real SÍ es un UserRole.
        return $this->getAttribute('role') === UserRole::Admin;
    }

    /** El segundo factor está ACTIVADO: la persona demostró que su app genera códigos válidos. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** Cuántos códigos de recuperación sin usar le quedan. */
    public function remainingRecoveryCodes(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /**
     * Borra todo rastro del segundo factor. Se usa cuando se traspasa el rol de administrador: el que
     * deja de serlo ya no lo necesita, y el nuevo activará el suyo en su siguiente acceso (R9, R21).
     */
    public function clearTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();
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
