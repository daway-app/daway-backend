<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PharmacyMedicine>
 */
class PharmacyMedicineFactory extends Factory
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
            'medicine_id' => Medicine::factory(),
            'price' => fake()->randomFloat(2, 1, 100),
            'quantity' => 20,
            'min_stock' => 5,
            'is_available' => true,
        ];
    }
}
