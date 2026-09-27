<?php

namespace Tests\Feature\Web;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineRequestAdminTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Subcategory $subcategory;
    private Pharmacy $pharmacy;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name_ar' => 'قسم الاعتماد',
            'name_en' => 'Appr Cat '.uniqid(),
            'slug' => 'appr-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->subcategory = Subcategory::create([
            'category_id' => $this->category->id,
            'name_ar' => 'فرعي',
            'name_en' => 'Sub '.uniqid(),
            'slug' => 'appr-sub-'.uniqid(),
            'group_key' => 'test',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->pharmacy = Pharmacy::factory()->create();
        $this->admin = User::factory()->admin()->create();
    }

    private function makeRequest(array $attrs = []): MedicineRequest
    {
        return MedicineRequest::create(array_merge([
            'pharmacy_id' => $this->pharmacy->id,
            'requested_by' => $this->pharmacy->user->id,
            'status' => MedicineRequest::STATUS_PENDING,
            'trade_name' => 'APPROVABLE MED',
            'active_ingredient' => 'TestIngredient',
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory->id,
        ], $attrs));
    }

    public function test_admin_lists_pending_requests(): void
    {
        $this->makeRequest();
        $this->actingAs($this->admin)->get(route('medicine_requests.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('APPROVABLE MED');
    }

    public function test_admin_show_request(): void
    {
        $request = $this->makeRequest();
        $this->actingAs($this->admin)->get(route('medicine_requests.show', $request->id))
            ->assertOk()
            ->assertSee('APPROVABLE MED');
    }

    public function test_non_admin_cannot_approve(): void
    {
        $request = $this->makeRequest();
        $pharmacyUser = $this->pharmacy->user;
        $this->actingAs($pharmacyUser)->post(route('medicine_requests.approve', $request->id))
            ->assertRedirect();
        $this->assertSame(MedicineRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_admin_approve_no_moh_match_creates_local_medicine(): void
    {
        $request = $this->makeRequest(['trade_name' => 'UNIQUE LOCAL MED']);

        $this->actingAs($this->admin)->post(route('medicine_requests.approve', $request->id))
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(MedicineRequest::STATUS_APPROVED, $request->status);
        $this->assertNull($request->approved_moh_medicine_id);
        $this->assertNotNull($request->approved_medicine_id);

        // local canonical created
        $this->assertDatabaseHas('medicines', ['id' => $request->approved_medicine_id, 'trade_name' => 'UNIQUE LOCAL MED']);

        // category link via medicine_id
        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $this->category->id,
            'medicine_id' => $request->approved_medicine_id,
        ]);

        // pharmacy inventory row
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $request->approved_medicine_id,
        ]);
    }

    public function test_admin_approve_moh_match_reuses_existing_moh(): void
    {
        MohMedicine::create(['trade_name' => 'EXISTING MOH MED', 'moh_product_id' => 555001]);
        $request = $this->makeRequest(['trade_name' => 'EXISTING MOH MED']);

        $this->actingAs($this->admin)->post(route('medicine_requests.approve', $request->id))->assertRedirect();

        $request->refresh();
        $this->assertSame(MedicineRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->approved_moh_medicine_id);
        $this->assertNull($request->approved_medicine_id);

        // link uses MOH stable key, not local duplicate
        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $this->category->id,
            'moh_product_id' => 555001,
        ]);
    }

    public function test_admin_reject_with_reason(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->admin)->post(route('medicine_requests.reject', $request->id), [
            'admin_notes' => 'duplicate entry',
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame(MedicineRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('duplicate entry', $request->admin_notes);
    }

    public function test_reject_requires_reason(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->admin)->post(route('medicine_requests.reject', $request->id), [])
            ->assertSessionHasErrors('admin_notes');
    }

    public function test_cannot_approve_non_pending_request(): void
    {
        $request = $this->makeRequest();
        $request->update(['status' => MedicineRequest::STATUS_REJECTED]);

        $this->actingAs($this->admin)->post(route('medicine_requests.approve', $request->id));

        $this->assertSame(MedicineRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_patient_cannot_access_admin_approval(): void
    {
        $request = $this->makeRequest();
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient)->get(route('medicine_requests.index'))
            ->assertRedirect('/login');
    }

    public function test_duplicate_local_medicine_not_created_when_reapproving(): void
    {
        $request = $this->makeRequest(['trade_name' => 'DEDUP MED']);
        $this->actingAs($this->admin)->post(route('medicine_requests.approve', $request->id));
        $localId = $request->fresh()->approved_medicine_id;
        $before = Medicine::count();

        // second request, same name — should reuse existing local medicine
        $request2 = $this->makeRequest(['trade_name' => 'DEDUP MED']);
        $this->actingAs($this->admin)->post(route('medicine_requests.approve', $request2->id));

        $this->assertSame($before, Medicine::count(), 'no duplicate local medicine created');
        $this->assertSame($localId, $request2->fresh()->approved_medicine_id);
    }
}
