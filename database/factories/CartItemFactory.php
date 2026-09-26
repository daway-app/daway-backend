<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        $pharmacyMedicine = PharmacyMedicine::inRandomOrder()->first()
            ?? PharmacyMedicine::factory();

        return [
            'cart_id' => Cart::factory(),
            'pharmacy_id' => $pharmacyMedicine->pharmacy_id,
            'pharmacy_medicine_id' => $pharmacyMedicine->id,
            'moh_medicine_id' => $pharmacyMedicine->moh_medicine_id,
            'quantity' => $this->faker->numberBetween(1, 10),
            'price' => $this->faker->randomFloat(2, 1, 500),
        ];
    }
}