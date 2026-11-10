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

use App\Enums\Department;
use App\Enums\Team;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TicketNotice;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Larastan lee el cuerpo de este método gracias a `parseModelCastsMethod: true` en phpstan.neon.
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

    /**
     * Avisos de tickets que esta persona PUEDE VER ahora. Una sola definición para la página «Avisos» y la campana:
     * si a alguien le quitan acceso a un ticket (p. ej. pasa a «Otros», R13) su aviso antiguo deja de verse y de contar.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function visibleNotices(): MorphMany
    {
        return $this->notifications()
            ->where('type', TicketNotice::class)
            ->whereIn(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.ticket_id')) AS UNSIGNED)"), Ticket::query()->visibleTo($this)->select('id'));
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

    /**
     * Valores de los departamentos que este empleado puede ver y atender (R11, R13, revisadas 2026-11-10):
     *  - el administrador, todos (ve y reasigna; no responde, R10);
     *  - un agente de Contabilidad, SOLO Contabilidad;
     *  - cualquier otro agente, todos menos Contabilidad.
     * Una sola definición para el ámbito Ticket::visibleTo y la policy: si cambia, cambia en los dos a la vez.
     *
     * @return list<string>
     */
    public function workableDepartments(): array
    {
        $all = array_map(fn (Department $d) => $d->value, Department::cases());

        return match (true) {
            $this->hasRole(UserRole::Admin) => $all,
            $this->hasRole(UserRole::Agent) && $this->team === Team::Accounting => [Department::Other->value],
            $this->hasRole(UserRole::Agent) => array_values(array_diff($all, [Department::Other->value])),
            default => [],
        };
    }

    public function canWorkOn(Department $department): bool
    {
        return in_array($department->value, $this->workableDepartments(), true);
    }

    /** ¿Tiene alguno de estos roles? */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->getAttribute('role'), $roles, true);
    }

    /** Empleado de Ling Marco (agente o administrador). */
    public function isInternal(): bool
    {
        return $this->hasRole(UserRole::Agent, UserRole::Admin);
    }

    /**
     * ¿Puede usar la aplicación AHORA? Activada, no dada de baja y, si es cliente, con su empresa de alta.
     * Es lo primero que se mira en toda autorización: una cuenta que no es operativa no puede NADA,
     * aunque conserve una sesión abierta de antes de la baja.
     */
    public function isOperational(): bool
    {
        if ($this->activated_at === null || $this->deactivated_at !== null) {
            return false;
        }

        return $this->company_id === null || ($this->company !== null && $this->company->isActive());
    }

    /**
     * R21 (revisada 2026-11-09): el segundo factor es OPCIONAL para el administrador, pero si lo tiene
     * activado se le exige en cada acceso. Solo el administrador puede activarlo (agentes y clientes no).
     * Trampa: no devolver `true` solo por ser administrador; volvería a bloquear la entrada a quien
     * nunca lo activó (la usuaria decidió que no sea obligatorio).
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasRole(UserRole::Admin) && $this->hasTwoFactorEnabled();
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
