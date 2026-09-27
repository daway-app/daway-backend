<?php

namespace Tests\Feature\Web;

use App\Models\Category;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تكامل Phase 8: دواء محلي معتمد يظهر تلقائياً في قسم المريض عبر
 * category_medicine_links.medicine_id + pharmacy_medicines المتوفرين،
 * ويُخفى الطلب المعلق (pending) عن المريض.
 */
class MedicineRequestPatientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Pharmacy $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name_ar' => 'قسم تكامل',
            'name_en' => 'Vis Cat '.uniqid(),
            'slug' => 'vis-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->pharmacy = Pharmacy::factory()->create(['is_active' => true]);
    }

    private function localMedicineWithStockInCategory(bool $available, int $qty): Medicine
    {
        $medicine = Medicine::create([
            'trade_name' => 'LOCAL VISIBLE MED '.uniqid(),
            'active_ingredient' => 'TestIng',
        ]);

        \App\Models\CategoryMedicineLink::create([
            'category_id' => $this->category->id,
            'medicine_id' => $medicine->id,
            'source' => \App\Models\CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 20,
            'quantity' => $qty,
            'is_available' => $available,
        ]);

        return $medicine;
    }

    public function test_available_local_medicine_appears_in_patient_category(): void
    {
        $medicine = $this->localMedicineWithStockInCategory(true, 15);

        $response = $this->getJson('/api/categories/'.$this->category->id.'/medicines');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains($medicine->trade_name, $names);

        // يجب أن يظهر كدواء محلي (medicine_id محدد) لا كـ MOH row
        $row = collect($response->json('data'))->firstWhere('trade_name', $medicine->trade_name);
        $this->assertSame($medicine->id, $row['medicine_id']);
    }

    public function test_unavailable_local_medicine_hidden_from_category(): void
    {
        $hidden = $this->localMedicineWithStockInCategory(false, 15);
        $this->localMedicineWithStockInCategory(true, 10); // تأكيد وجود دواء متوفر

        $response = $this->getJson('/api/categories/'.$this->category->id.'/medicines')->assertOk();
        $names = collect($response->json('data'))->pluck('trade_name')->all();

        $this->assertNotContains($hidden->trade_name, $names);
    }

    public function test_zero_quantity_local_medicine_hidden(): void
    {
        $zero = $this->localMedicineWithStockInCategory(true, 0);

        $response = $this->getJson('/api/categories/'.$this->category->id.'/medicines')->assertOk();
        $names = collect($response->json('data'))->pluck('trade_name')->all();

        $this->assertNotContains($zero->trade_name, $names);
    }

    public function test_duplicate_pharmacies_show_local_medicine_once(): void
    {
        $medicine = $this->localMedicineWithStockInCategory(true, 10);

        // صيدلية ثانية بنفس الدواء
        $otherPharmacy = Pharmacy::factory()->create(['is_active' => true]);
        PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 25,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $response = $this->getJson('/api/categories/'.$this->category->id.'/medicines')->assertOk();
        $occurrences = collect($response->json('data'))
            ->filter(fn ($row) => $row['medicine_id'] === $medicine->id)
            ->count();

        $this->assertSame(1, $occurrences, 'دواء محلي في صيدليتين يظهر مرة واحدة');
    }

    public function test_local_medicine_count_reflects_available_only(): void
    {
        $this->localMedicineWithStockInCategory(true, 10);
        $this->localMedicineWithStockInCategory(false, 10); // مخفي

        $count = $this->getJson('/api/categories/'.$this->category->id)->assertOk()->json('data.medicines_count');

        $this->assertSame(1, $count, 'العدد يخص الأدوية المتوفرة فقط');
    }

    public function test_pending_request_medicine_not_visible_before_approval(): void
    {
        // دواء محلي جديد مرتبط بالقسم لكن بدون مخزون متوفر (حالة pending قبل approval)
        $medicine = Medicine::create([
            'trade_name' => 'PENDING MED '.uniqid(),
            'active_ingredient' => 'TestIng',
        ]);
        \App\Models\CategoryMedicineLink::create([
            'category_id' => $this->category->id,
            'medicine_id' => $medicine->id,
            'source' => \App\Models\CategoryMedicineLink::SOURCE_ADMIN,
            'confidence' => 100,
            'needs_review' => false,
        ]);
        // لا يوجد pharmacy_medicines متوفر له

        $response = $this->getJson('/api/categories/'.$this->category->id.'/medicines')->assertOk();
        $names = collect($response->json('data'))->pluck('trade_name')->all();

        $this->assertNotContains($medicine->trade_name, $names);
    }
}
