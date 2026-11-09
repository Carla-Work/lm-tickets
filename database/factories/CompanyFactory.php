<?php

/*
 * Datos de prueba de empresas. Cuándo tocarlo: al añadir datos a Company.
 */

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'tax_id' => 'B'.fake()->unique()->numerify('########'),
            'phone' => fake()->numerify('928 ### ###'),
            'contact_email' => fake()->unique()->safeEmail(),
        ];
    }

    /** Empresa dada de baja. */
    public function deactivated(): static
    {
        return $this->state(fn () => ['deactivated_at' => now()]);
    }
}
