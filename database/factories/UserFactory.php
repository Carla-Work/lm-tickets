<?php

/*
 * Datos de prueba de cuentas. Cuándo tocarlo: al cambiar el modelo de roles o de cuenta.
 *
 * Por defecto crea un USUARIO de una empresa (el rol de menos privilegio). Los demás roles se
 * piden a propósito con `companyAdmin()`, `agent()` o `admin()`: así un test nunca tiene un
 * administrador por accidente. Cada estado cumple las restricciones de la base de datos.
 */

namespace Database\Factories;

use App\Enums\Team;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** La contraseña de todas las cuentas de prueba es «password» (se calcula una vez: bcrypt es lento). */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::CompanyUser->value,
            'company_id' => Company::factory(),
            'team' => null,
            'activated_at' => now(),
        ];
    }

    public function companyAdmin(): static
    {
        return $this->state(fn () => ['role' => UserRole::CompanyAdmin->value]);
    }

    public function agent(Team $team = Team::Support): static
    {
        return $this->state(fn () => ['role' => UserRole::Agent->value, 'company_id' => null, 'team' => $team->value]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin->value, 'company_id' => null, 'team' => null]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    /** Cuenta invitada que todavía no ha elegido contraseña (R18). */
    public function invited(): static
    {
        return $this->state(fn () => ['activated_at' => null]);
    }

    /** Cuenta dada de baja. */
    public function deactivated(): static
    {
        return $this->state(fn () => ['deactivated_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
