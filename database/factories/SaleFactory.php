<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Pharmacy;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'pharmacy_id' => Pharmacy::factory(),
            'number' => 'INV-'.rand(1000, 9999),
            'customer_id' => null,
            'customer_name' => null,
            'subtotal' => 0,
            'discount' => 0,
            'total' => $this->faker->randomFloat(2, 100, 2000),
            'paid' => 0,
            'remaining' => 0,
            'payment_method' => Sale::METHOD_CASH,
            'status' => Sale::STATUS_PAID,
            'items_count' => 1,
            'notes' => null,
            'created_by' => User::factory(),
            'sold_at' => Carbon::now(),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (array $attributes) => [
            'paid' => $attributes['total'],
            'remaining' => 0,
            'status' => Sale::STATUS_PAID,
        ]);
    }

    public function withItems(int $count = 2): self
    {
        return $this->afterCreating(function (Sale $sale) use ($count) {
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $quantity = rand(1, 10);
                $unitPrice = $this->faker->randomFloat(2, 10, 200);
                $lineTotal = round($unitPrice * $quantity, 2);
                $total += $lineTotal;

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'medicine_id' => null,
                    'pharmacy_medicine_id' => null,
                    'medicine_name' => $this->faker->words(2, true),
                    'barcode' => $this->faker->optional()->regexify('[0-9]{13}'),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_discount' => 0,
                    'line_total' => $lineTotal,
                ]);
            }

            $sale->update([
                'total' => $total,
                'paid' => $total,
                'remaining' => 0,
                'items_count' => $count,
            ]);
        });
    }
}