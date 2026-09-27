<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\MedicineRequest;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicineRequest>
 */
class MedicineRequestFactory extends Factory
{
    protected $model = MedicineRequest::class;

    public function definition(): array
    {
        $category = Category::create([
            'name_ar' => 'قسم الطلبات',
            'name_en' => 'Req Cat '.fake()->unique()->numerify('####'),
            'slug' => 'req-cat-'.fake()->unique()->numerify('####'),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [
            'pharmacy_id' => Pharmacy::factory(),
            'requested_by' => fn (array $attrs) => User::factory()->pharmacy()->create()->id,
            'status' => MedicineRequest::STATUS_PENDING,
            'trade_name' => strtoupper(fake()->unique()->words(3, true)).' '.$fake()->randomElement(['500MG', '10MG', 'SYRUP']),
            'trade_name_ar' => null,
            'generic_name' => fake()->words(2, true),
            'manufacturer' => fake()->company(),
            'active_ingredient' => fake()->word(),
            'dosage_form' => fake()->randomElement(['Tablet', 'Syrup', 'Injection']),
            'packaging' => null,
            'origin' => 'Local',
            'company' => null,
            'official_price' => $this->faker->randomFloat(2, 1, 500),
            'barcode' => null,
            'image' => null,
            'category_id' => $category->id,
            'subcategory_id' => null,
            'admin_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_moh_medicine_id' => null,
            'approved_medicine_id' => null,
        ];
    }
}
