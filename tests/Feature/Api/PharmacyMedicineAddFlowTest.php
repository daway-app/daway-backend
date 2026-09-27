<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6 — Pharmacy Medicine Add Flow:
 *  - canonical add via moh_medicine_id persists the MOH link on the inventory row
 *  - duplicate prevention within a pharmacy
 *  - ownership / cross-pharmacy isolation
 *  - availability integration with patient categories
 */
class PharmacyMedicineAddFlowTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function makeCategoryAndLink(int $mohProductId, ?int $drugId = null): Category
    {
        $category = Category::create([
            'name_ar' => 'أدوية الاختبار',
            'name_en' => 'Test Category '.uniqid(),
            'slug' => 'test-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => $mohProductId,
            'moh_drug_id' => $drugId,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        return $category;
    }

    public function test_add_via_moh_id_persists_moh_medicine_id_on_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'LINKED MOH MED',
            'moh_product_id' => 601,
            'generic_name' => 'LinkedGeneric',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 25,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'moh_medicine_id' => $moh->id,
            'price' => 25,
            'quantity' => 10,
            'is_available' => true,
        ]);

        // لا يُنشئ دواءً canonical مكررًا — يطابق بمسمى الكتالوج
        $this->assertSame(1, Medicine::where('trade_name', 'LINKED MOH MED')->count());
    }

    public function test_add_existing_moh_medicine_reuses_same_local_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'SHARED MOH MED',
            'moh_product_id' => 602,
            'generic_name' => 'SharedGeneric',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(201);

        $otherPharmacy = Pharmacy::factory()->create(['user_id' => User::factory()->pharmacy()->create()->id]);
        Sanctum::actingAs($otherPharmacy->user);

        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 12,
            'quantity' => 7,
        ])->assertStatus(201);

        // نفس الدواء canonical لكل الصيدليتين — لا duplicate
        $this->assertSame(1, Medicine::where('trade_name', 'SHARED MOH MED')->count());
        $this->assertSame(2, PharmacyMedicine::where('moh_medicine_id', $moh->id)->count());
    }

    public function test_duplicate_add_same_moh_in_same_pharmacy_is_rejected(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'DUP MOH MED',
            'moh_product_id' => 603,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(201);

        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(422);

        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_invalid_moh_medicine_id_is_rejected(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => 999999,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(422);
    }

    public function test_invalid_medicine_id_is_rejected(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => 999999,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(422);
    }

    public function test_price_must_be_non_negative(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => -5,
            'quantity' => 5,
        ])->assertStatus(422);
    }

    public function test_quantity_must_be_non_negative(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => -1,
        ])->assertStatus(422);
    }

    public function test_pharmacy_a_cannot_update_pharmacy_b_inventory(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($userB);

        $this->putJson('/api/pharmacy/medicines/'.$row->id, [
            'medicine_id' => $medicine->id,
            'price' => 99,
            'quantity' => 99,
            'is_available' => true,
        ])->assertStatus(404);

        $this->assertSame(10.0, (float) $row->fresh()->price);
    }

    public function test_pharmacy_a_cannot_delete_pharmacy_b_inventory(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($userB);

        $this->deleteJson('/api/pharmacy/medicines/'.$row->id)->assertStatus(404);

        $this->assertNotNull(PharmacyMedicine::find($row->id));
    }

    public function test_patient_cannot_use_pharmacy_add_flow(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
        ])->assertStatus(403);
    }

    // ── Availability integration with patient categories ──────────────

    public function test_added_moh_medicine_becomes_visible_in_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'CAT VISIBLE MED',
            'moh_product_id' => 701,
        ]);
        $category = $this->makeCategoryAndLink(701);

        Sanctum::actingAs($user);
        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 15,
            'quantity' => 10,
            'is_available' => true,
        ])->assertStatus(201);

        $this->getJson('/api/categories/'.$category->id.'/medicines')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_unavailable_added_medicine_hidden_from_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'CAT HIDDEN MED',
            'moh_product_id' => 702,
        ]);
        $category = $this->makeCategoryAndLink(702);

        Sanctum::actingAs($user);
        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 15,
            'quantity' => 10,
            'is_available' => false,
        ])->assertStatus(201);

        $this->getJson('/api/categories/'.$category->id.'/medicines')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_zero_quantity_added_medicine_hidden_from_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $moh = MohMedicine::create([
            'trade_name' => 'CAT ZERO MED',
            'moh_product_id' => 703,
        ]);
        $category = $this->makeCategoryAndLink(703);

        Sanctum::actingAs($user);
        $this->postJson('/api/pharmacy/medicines', [
            'moh_medicine_id' => $moh->id,
            'price' => 15,
            'quantity' => 0,
            'is_available' => true,
        ])->assertStatus(201);

        $this->getJson('/api/categories/'.$category->id.'/medicines')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }
}
