<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\MedicineRequest;
use App\Models\Pharmacy;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Subcategory $subcategory;
    private Pharmacy $pharmacy;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name_ar' => 'أقسام الاختبار',
            'name_en' => 'Test Cat '.uniqid(),
            'slug' => 'test-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->subcategory = Subcategory::create([
            'category_id' => $this->category->id,
            'name_ar' => 'فرعي',
            'name_en' => 'Sub '.uniqid(),
            'slug' => 'test-sub-'.uniqid(),
            'group_key' => 'test',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->user = User::factory()->pharmacy()->create();
        $this->pharmacy = Pharmacy::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    }

    public function test_create_request_success(): void
    {
        $response = $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'NEWDRUG XYZ',
            'generic_name' => 'NewGeneric',
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $this->assertDatabaseHas('medicine_requests', [
            'pharmacy_id' => $this->pharmacy->id,
            'trade_name' => 'NEWDRUG XYZ',
            'status' => MedicineRequest::STATUS_PENDING,
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory->id,
        ]);
    }

    public function test_request_is_linked_to_pharmacy_from_context_not_client(): void
    {
        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'CTX DRUG',
            'category_id' => $this->category->id,
            'pharmacy_id' => 999999, // client-supplied should be ignored
        ])->assertStatus(201);

        $this->assertDatabaseHas('medicine_requests', [
            'trade_name' => 'CTX DRUG',
            'pharmacy_id' => $this->pharmacy->id,
        ]);
    }

    public function test_create_request_requires_category(): void
    {
        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'NOCAT DRUG',
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_create_request_requires_english_trade_name(): void
    {
        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'دواء عربي',
            'category_id' => $this->category->id,
        ])->assertStatus(422)->assertJsonValidationErrors('trade_name');
    }

    public function test_invalid_category_rejected(): void
    {
        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'BAD CAT',
            'category_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_subcategory_from_wrong_category_rejected(): void
    {
        $otherCategory = Category::create([
            'name_ar' => 'قسم آخر',
            'name_en' => 'Other '.uniqid(),
            'slug' => 'other-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $otherSub = Subcategory::create([
            'category_id' => $otherCategory->id,
            'name_ar' => 'فرعي آخر',
            'name_en' => 'OtherSub '.uniqid(),
            'slug' => 'other-sub-'.uniqid(),
            'group_key' => 'other',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'WRONG SUB',
            'category_id' => $this->category->id,
            'subcategory_id' => $otherSub->id,
        ])->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
    }

    public function test_invalid_subcategory_rejected(): void
    {
        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'BAD SUB',
            'category_id' => $this->category->id,
            'subcategory_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
    }

    public function test_list_own_requests(): void
    {
        MedicineRequest::create([
            'pharmacy_id' => $this->pharmacy->id,
            'requested_by' => $this->user->id,
            'trade_name' => 'OWN REQ',
            'category_id' => $this->category->id,
            'status' => MedicineRequest::STATUS_PENDING,
        ]);

        $this->getJson('/api/pharmacy/medicine-requests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_name', 'OWN REQ');
    }

    public function test_cannot_list_another_pharmacy_requests(): void
    {
        $otherPharmacy = Pharmacy::factory()->create(['user_id' => User::factory()->pharmacy()->create()->id]);
        MedicineRequest::create([
            'pharmacy_id' => $otherPharmacy->id,
            'requested_by' => $this->user->id,
            'trade_name' => 'OTHERS REQ',
            'category_id' => $this->category->id,
            'status' => MedicineRequest::STATUS_PENDING,
        ]);

        $this->getJson('/api/pharmacy/medicine-requests')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_patient_cannot_create_request(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/pharmacy/medicine-requests', [
            'trade_name' => 'PATIENT DRUG',
            'category_id' => $this->category->id,
        ])->assertStatus(403);
    }
}
