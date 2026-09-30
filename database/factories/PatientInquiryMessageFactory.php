<?php

namespace Database\Factories;

use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientInquiryMessage>
 */
class PatientInquiryMessageFactory extends Factory
{
    protected $model = PatientInquiryMessage::class;

    public function definition(): array
    {
        return [
            'patient_inquiry_id' => PatientInquiry::factory(),
            'sender_user_id' => User::factory(),
            'message' => fake()->sentence(),
            'read_at' => null,
        ];
    }
}
