<?php

/*
 * Datos de prueba de categorías. Cuándo tocarlo: al añadir datos a Category.
 */

namespace Database\Factories;

use App\Enums\Department;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department' => Department::Support->value,
            'name' => fake()->unique()->words(3, true),
            'active' => true,
        ];
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(fn () => ['department' => $department->value]);
    }
}
