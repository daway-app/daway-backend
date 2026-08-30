<?php

namespace Tests\Unit;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

/**
 * C5: يتحقق أن حقل min_stock على جدول pharmacy_medicines
 * أصبح قابلاً للكتابة عبر Eloquent (يوجد في $fillable + cast: integer).
 */
class PharmacyMedicineMinStockTest extends TestCase
{
    public function test_min_stock_is_mass_assignable_via_create(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 20,
            'min_stock' => 7,
            'is_available' => true,
        ]);

        $this->assertSame(7, (int) $pm->fresh()->min_stock);
    }

    public function test_min_stock_is_cast_to_integer(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 20,
            'min_stock' => 3,
            'is_available' => true,
        ]);

        $fresh = $pm->fresh();
        $this->assertIsInt($fresh->min_stock);
        $this->assertIsInt($fresh->quantity);
    }
}
