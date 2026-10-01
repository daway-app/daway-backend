<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Pharmacy;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'pharmacy_id' => Pharmacy::factory(),
            'name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->optional()->email(),
            'credit_limit' => $this->faker->randomFloat(2, 100, 5000),
            'current_balance' => 0,
            'is_active' => true,
        ];
    }

    public function withBalance(float $balance): self
    {
        return $this->state(['current_balance' => $balance]);
    }
}