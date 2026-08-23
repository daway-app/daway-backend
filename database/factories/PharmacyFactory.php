<?php

namespace Database\Factories;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pharmacy>
 */
class PharmacyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->pharmacy(),
            'pharmacy_custom_id' => 'PH-'.fake()->unique()->numerify('####'),
            'pharmacy_name' => fake()->company().' Pharmacy',
            'address' => fake()->address(),
            'latitude' => fake()->latitude(31.2, 31.8),
            'longitude' => fake()->longitude(34.2, 34.6),
            'phone_number' => fake()->numerify('059#######'),
            'logo' => null,
            'avg_rating' => 0.00,
            'is_active' => true,
            'profile_completed_at' => now(),
            'region' => fake()->city(),
        ];
    }
}
