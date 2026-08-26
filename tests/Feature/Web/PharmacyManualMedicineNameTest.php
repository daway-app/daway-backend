<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * الإضافة اليدوية للويب: الاسم الإنجليزي إلزامي والعربي اختياري — نفس قواعد by-name في الـ API.
 */
class PharmacyManualMedicineNameTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_manual_add_rejects_arabic_trade_name(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->from(route('pharmacy.medicines.create'))
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'بنادول اكسترا',
                'active_ingredient' => 'Paracetamol',
                'price' => 10,
                'quantity' => 5,
                'is_available' => 1,
            ])
            ->assertSessionHasErrors('trade_name');

        $this->assertDatabaseMissing('medicines', ['trade_name' => 'بنادول اكسترا']);
    }

    public function test_manual_add_accepts_english_name_with_optional_arabic(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'Panadol Advance',
                'trade_name_ar' => 'بنادول أدفانس',
                'active_ingredient' => 'Paracetamol',
                'price' => 9,
                'quantity' => 12,
                'is_available' => 1,
            ])
            ->assertRedirect();

        $medicine = Medicine::where('trade_name', 'Panadol Advance')->first();
        $this->assertNotNull($medicine);
        $this->assertSame('بنادول أدفانس', $medicine->trade_name_ar);
    }

    public function test_manual_add_rejects_non_arabic_arabic_name_field(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->from(route('pharmacy.medicines.create'))
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'Not Arabic AR Field',
                'trade_name_ar' => 'english text',
                'active_ingredient' => 'Caffeine',
                'price' => 5,
                'quantity' => 3,
                'is_available' => 1,
            ])
            ->assertSessionHasErrors('trade_name_ar');

        $this->assertDatabaseMissing('medicines', ['trade_name' => 'Not Arabic AR Field']);
    }
}
