<?php

namespace Database\Factories;

use App\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_name' => fake()->unique()->words(2, true),
            'active_ingredient' => fake()->word(),
            'description' => fake()->sentence(),
            'image' => null,
            'is_available' => true,
            'stock' => 100,
        ];
    }
}
