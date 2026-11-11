<?php

/*
 * Datos de prueba de etiquetas. Cuándo tocarlo: al añadir datos a Tag.
 */

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'active' => true];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
