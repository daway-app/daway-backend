<?php

namespace Database\Factories;

use App\Models\Pharmacy;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RatingFactory extends Factory
{
    protected $model = Rating::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->patient(),
            'pharmacy_id' => Pharmacy::factory(),
            'stars_rating' => $this->faker->numberBetween(1, 5),
            'comment' => $this->faker->optional()->sentence(),
        ];
    }
}
