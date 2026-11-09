<?php

/*
 * Datos de prueba de productos. Cuándo tocarlo: al añadir datos a Product.
 */

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'one'.fake()->unique()->lexify('????'),
            'description' => fake()->sentence(4),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
