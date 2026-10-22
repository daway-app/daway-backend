<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\CategoryCatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UncategorizedMedicinesApiTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function makeCategory(bool $active = true): Category
    {
        return Category::create([
            'name_ar' => 'قسم الاختبار '.uniqid(),
            'name_en' => 'Test Category '.uniqid(),
            'slug' => 'test-cat-'.uniqid(),
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function makeSubcategory(Category $category, bool $active = true): Subcategory
    {
        return Subcategory::create([
            'category_id' => $category->id,
            'name_ar' => 'قسم فرعي '.uniqid(),
            'name_en' => 'Test Subcategory '.uniqid(),
            'slug' => 'test-sub-'.uniqid(),
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function linkToCategory(int $medicineId, Category $category): CategoryMedicineLink
    {
        return CategoryMedicineLink::create([
            'category_id' => $category->id,
            'medicine_id' => $medicineId,
            'moh_product_id' => null,
            'moh_drug_id' => null,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);
    }

    public function test_list_returns_only_current_pharmacy_unlinked_medicines(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        [, $otherPharmacy] = $this->pharmacyUserWithPharmacy();

        $unlinked = Medicine::factory()->create([
            'trade_name' => 'UNLINKED MED',
            'trade_name_ar' => 'دواء غير مصنف',
            'description' => 'وصف الدواء غير المصنف',
            'active_ingredient' => 'Paracetamol',
        ]);
        $linked = Medicine::factory()->create(['trade_name' => 'LINKED MED']);
        $otherMedicine = Medicine::factory()->create(['trade_name' => 'OTHER PHARMACY MED']);

        $unlinkedRow = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $unlinked->id,
            'quantity' => 17,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $linked->id,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $otherMedicine->id,
        ]);

        $this->linkToCategory($linked->id, $this->makeCategory());

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines/uncategorized');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unlinkedRow->id)
            ->assertJsonPath('data.0.medicine_id', $unlinked->id)
            ->assertJsonPath('data.0.trade_name', 'UNLINKED MED')
            ->assertJsonPath('data.0.trade_name_ar', 'دواء غير مصنف')
            ->assertJsonPath('data.0.description', 'وصف الدواء غير المصنف')
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol')
            ->assertJsonPath('data.0.quantity', 17)
            ->assertJsonPath('pagination.total', 1);

        $response->assertJsonMissing(['medicine_id' => $linked->id]);
        $response->assertJsonMissing(['medicine_id' => $otherMedicine->id]);
    }

    public function test_q_search_filters_uncategorized_medicines(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $match = Medicine::factory()->create(['trade_name' => 'SEARCHABLE TOKEN MED']);
        $other = Medicine::factory()->create(['trade_name' => 'DIFFERENT MED']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $match->id,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $other->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/medicines/uncategorized?q=Searchable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.medicine_id', $match->id)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_assign_creates_pharmacy_link_and_bumps_catalog_cache(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $category = $this->makeCategory();
        $subcategory = $this->makeSubcategory($category);

        Sanctum::actingAs($user);

        $versionBefore = CategoryCatalogCache::version();

        $response = $this->patchJson('/api/pharmacy/medicines/'.$row->id.'/category', [
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicine_id', $medicine->id)
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.subcategory_id', $subcategory->id);

        $this->assertGreaterThan($versionBefore, CategoryCatalogCache::version());

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'medicine_id' => $medicine->id,
            'moh_product_id' => null,
            'moh_drug_id' => null,
            'source' => CategoryMedicineLink::SOURCE_PHARMACY,
            'confidence' => 100,
            'needs_review' => false,
        ]);
    }

    public function test_assign_rejects_another_pharmacys_medicine(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();
        [, $otherPharmacy] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $category = $this->makeCategory();

        Sanctum::actingAs($user);

        $this->patchJson('/api/pharmacy/medicines/'.$row->id.'/category', [
            'category_id' => $category->id,
        ])->assertStatus(404);

        $this->assertDatabaseCount('category_medicine_links', 0);
    }

    public function test_assign_rejects_already_categorized_medicine_without_overwriting(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $existingCategory = $this->makeCategory();
        $newCategory = $this->makeCategory();
        $existingLink = $this->linkToCategory($medicine->id, $existingCategory);

        Sanctum::actingAs($user);

        $this->patchJson('/api/pharmacy/medicines/'.$row->id.'/category', [
            'category_id' => $newCategory->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('category_medicine_links', 1);
        $this->assertDatabaseHas('category_medicine_links', [
            'id' => $existingLink->id,
            'category_id' => $existingCategory->id,
            'medicine_id' => $medicine->id,
        ]);
        $this->assertDatabaseMissing('category_medicine_links', [
            'category_id' => $newCategory->id,
            'medicine_id' => $medicine->id,
        ]);
    }

    public function test_assign_rejects_inactive_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $category = $this->makeCategory(active: false);

        Sanctum::actingAs($user);

        $this->patchJson('/api/pharmacy/medicines/'.$row->id.'/category', [
            'category_id' => $category->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('category_medicine_links', 0);
    }

    public function test_assign_rejects_subcategory_belonging_to_another_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $row = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $category = $this->makeCategory();
        $otherCategory = $this->makeCategory();
        $foreignSubcategory = $this->makeSubcategory($otherCategory);

        Sanctum::actingAs($user);

        $this->patchJson('/api/pharmacy/medicines/'.$row->id.'/category', [
            'category_id' => $category->id,
            'subcategory_id' => $foreignSubcategory->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('category_medicine_links', 0);
    }
}
