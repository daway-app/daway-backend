<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientInquiry>
 */
class PatientInquiryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->patient(),
            'pharmacy_id' => Pharmacy::factory(),
            'medicine_id' => Medicine::factory(),
            'message' => fake()->optional()->sentence(),
            'status' => 'new',
        ];
    }
}
