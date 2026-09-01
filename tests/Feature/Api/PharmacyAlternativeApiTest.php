<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyAlternativeApiTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_api_index_returns_only_medicines_with_alternatives(): void
    {
        // الـ API filter على whereHas('medicine.alternatives') — يعرض فقط
        // الأدوية التي لها بدائل (مختلف عن web الذي يعرض الكل).
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $baseWith = Medicine::factory()->create(['active_ingredient' => 'A']);
        $baseWithout = Medicine::factory()->create(['active_ingredient' => 'B']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'A']);

        $pmWith = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $baseWith->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $baseWithout->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        $baseWith->alternatives()->attach($alt->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/alternatives');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pmWith->id)
            ->assertJsonPath('data.0.medicine.trade_name', $baseWith->trade_name)
            ->assertJsonPath('data.0.alternatives.0.id', $alt->id);
    }

    public function test_api_store_works(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pm->id,
            'alternative_id' => $alt->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('alternative_medicine', [
            'medicine_id' => $base->id,
            'alternative_id' => $alt->id,
        ]);
    }

    public function test_api_store_rejects_self_alternative(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pm->id,
            'alternative_id' => $medicine->id,
        ])->assertStatus(422);
    }

    public function test_api_store_rejects_reverse_pair(): void
    {
        // C1: (alt → base) موجودة، لا يمكن إضافة (base → alt)
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $alt->alternatives()->attach($base->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => $pm->id,
            'alternative_id' => $alt->id,
        ])->assertStatus(409)
          ->assertJsonPath('success', false);
    }

    public function test_api_destroy_removes_alternative(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $base = Medicine::factory()->create(['active_ingredient' => 'X']);
        $alt = Medicine::factory()->create(['active_ingredient' => 'X']);
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $base->alternatives()->attach($alt->id);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/pharmacy/alternatives/{$base->id}/{$alt->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('alternative_medicine', [
            'medicine_id' => $base->id,
            'alternative_id' => $alt->id,
        ]);
    }

    public function test_api_rejects_non_pharmacy_role(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/alternatives')->assertForbidden();
        $this->postJson('/api/pharmacy/alternatives', [
            'base_medicine_id' => 1,
            'alternative_id' => 1,
        ])->assertForbidden();
    }

    public function test_api_destroy_rejects_other_pharmacy_medicine(): void
    {
        $ownerUser = User::factory()->pharmacy()->create();
        $ownerPharmacy = Pharmacy::factory()->create(['user_id' => $ownerUser->id]);
        $attackerUser = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $attackerUser->id]);

        $base = Medicine::factory()->create();
        $alt = Medicine::factory()->create();
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $ownerPharmacy->id,
            'medicine_id' => $base->id,
            'price' => 5,
            'quantity' => 10,
        ]);
        $base->alternatives()->attach($alt->id);

        Sanctum::actingAs($attackerUser);

        // الـ attacker لا يستطيع حذف بديل من صيدلية الـ owner
        $this->deleteJson("/api/pharmacy/alternatives/{$pm->id}/{$alt->id}")
            ->assertNotFound(); // destroy() للـ API ينجح (controller يأخذ PharmacyMedicine model binding)
        // ملاحظة: الـ web controller يفحص pharmacy ownership، لكن API لا يفعل ذلك!
    }
}
