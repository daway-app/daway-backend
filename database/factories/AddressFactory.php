<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->patient(),
            'label' => fake()->randomElement(['Home', 'Work', 'Family']),
            'recipient_name' => fake()->name(),
            'phone' => '059' . fake()->numerify('########'),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(31.0, 32.0),
            'longitude' => fake()->longitude(35.0, 36.0),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}