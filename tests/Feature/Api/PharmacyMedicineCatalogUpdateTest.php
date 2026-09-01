<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test that the mobile API correctly updates the medicine catalog
 * (trade_name, trade_name_ar, active_ingredient) when the user
 * edits a pharmacy_medicine entry.
 */
class PharmacyMedicineCatalogUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_api_update_changes_catalog_when_medicine_is_only_in_this_pharmacy(): void
    {
        // الـ catalog ينتمي لهذه الصيدلية فقط (لا صيدلية أخرى)
        // → التحديث يطبّق على trade_name و active_ingredient
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'OldName',
            'active_ingredient' => 'OldIngredient',
        ]);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'price' => 6,
            'is_available' => true,
            'trade_name' => 'NewName',
            'active_ingredient' => 'NewIngredient',
        ])->assertOk();

        $this->assertDatabaseHas('medicines', [
            'id' => $medicine->id,
            'trade_name' => 'NewName',
            'active_ingredient' => 'NewIngredient',
        ]);
    }

    public function test_api_update_does_not_change_catalog_when_other_pharmacies_use_same_medicine(): void
    {
        // صيدلية أخرى تستخدم نفس الـ medicine → لا نغير الـ catalog
        // (الـ catalog ملك عام، تغييره يؤثر على صيدلية أخرى)
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $otherPharmacy = Pharmacy::factory()->create();
        $otherUser = User::factory()->pharmacy()->create();
        $otherPharmacy->user_id = $otherUser->id;
        $otherPharmacy->save();

        $medicine = Medicine::factory()->create([
            'trade_name' => 'OriginalName',
            'active_ingredient' => 'OriginalIngredient',
        ]);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        // صيدلية أخرى تستخدم نفس الـ medicine
        PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 7,
            'quantity' => 3,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'price' => 6,
            'is_available' => true,
            'trade_name' => 'ShouldNotChange',
            'active_ingredient' => 'ShouldNotChange',
        ])->assertOk();

        // الـ catalog يبقى كما هو (لم تتغير)
        $this->assertDatabaseHas('medicines', [
            'id' => $medicine->id,
            'trade_name' => 'OriginalName',
            'active_ingredient' => 'OriginalIngredient',
        ]);
    }

    public function test_api_update_persists_when_medicine_belongs_to_multiple_pharmacies_of_same_user(): void
    {
        // نفس الـ user لكن medicine يُستخدم في صيدلية أخرى أيضاً (مستقبلاً)
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $otherPharmacy = Pharmacy::factory()->create();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'InitialName',
        ]);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 7,
            'quantity' => 3,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'price' => 6,
            'is_available' => true,
        ])->assertOk();

        // لا تغيير في الـ catalog (صيدلية أخرى موجودة)
        $this->assertDatabaseHas('medicines', [
            'id' => $medicine->id,
            'trade_name' => 'InitialName',
        ]);
    }
}
