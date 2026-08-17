<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '0599'.fake()->unique()->numerify('#######'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'role' => 'patient',
            'is_active' => true,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model's role is admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Indicate that the model's role is pharmacy.
     */
    public function pharmacy(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'pharmacy',
        ]);
    }

    /**
     * Indicate that the model's role is patient.
     */
    public function patient(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'patient',
        ]);
    }
}
