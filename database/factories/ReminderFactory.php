<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'medicine_name' => fake()->word(),
            'dosage' => '1 tablet',
            'reminder_date' => now()->toDateString(),
            'reminder_time' => '09:00',
            'frequency' => 'daily',
            'quantity_remaining' => 10,
            'is_active' => true,
        ];
    }
}
