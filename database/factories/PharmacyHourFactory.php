<?php

namespace Database\Factories;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PharmacyHour>
 */
class PharmacyHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pharmacy_id' => Pharmacy::factory(),
            'day' => null,
            'day_of_week' => fake()->randomElement([
                'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday',
            ]),
            'opening_time' => null,
            'closing_time' => null,
            'open_time' => '09:00',
            'close_time' => '17:00',
            'is_closed' => false,
        ];
    }
}
