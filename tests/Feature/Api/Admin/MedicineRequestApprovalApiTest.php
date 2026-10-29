<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Support\CategoryCatalogCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * اعتماد طلبات الأدوية عبر الـAPI — بعد استخراج المنطق إلى
 * `MedicineRequestApprovalService` المشترك مع الويب.
 *
 * المطلوب من هذه المجموعة: **لا تغيّر أي سلوك خارجي مرئي** بعد الاستخراج.
 * نفس الحدود (422 لنفس الحالات)، نفس الآثار الجانبية، نفس حدود المعاملة.
 *
 * 🔴 الحاكم: كتالوج الوزارة يُربط بمفاتيح `moh_product_id`/`moh_drug_id`
 *    المستقرة — أبداً `moh_medicines.id`.
 */
class MedicineRequestApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/medicine-requests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function category(): Category
    {
        return Category::create([
            'name_ar' => 'مسكنات',
            'name_en' => 'Analgesics',
            'slug' => 'analgesics-'.uniqid(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function pendingRequest(Category $category, array $overrides = []): MedicineRequest
    {
        $pharmacyUser = $this->userWithRole('pharmacy');
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);

        return MedicineRequest::create(array_merge([
            'pharmacy_id' => $pharmacy->id,
            'requested_by' => $pharmacyUser->id,
            'status' => MedicineRequest::STATUS_PENDING,
            'trade_name' => 'Panadol Extra',
            'official_price' => 12.50,
            'category_id' => $category->id,
        ], $overrides));
    }

    // ══════════════════════════════════════════════════════════════════
    // النجاح
    // ══════════════════════════════════════════════════════════════════

    public function test_admin_can_approve_a_local_medicine_request(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', MedicineRequest::STATUS_APPROVED);

        $request->refresh();

        $this->assertSame(MedicineRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($admin->id, (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNull($request->approved_moh_medicine_id);
        $this->assertNotNull($request->approved_medicine_id);

        // دواء محلي أُنشئ + رابط قسم بالمفتاح المحلي + سطر مخزون صفر
        $medicine = Medicine::where('trade_name', 'Panadol Extra')->firstOrFail();
        $this->assertSame($medicine->id, (int) $request->approved_medicine_id);

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $category->id,
            'medicine_id' => $medicine->id,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
        ]);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $request->pharmacy_id,
            'medicine_id' => $medicine->id,
            'quantity' => 0,
            'is_available' => false,
        ]);
    }

    public function test_admin_can_approve_a_request_matching_the_moh_catalog(): void
    {
        $category = $this->category();

        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'moh_product_id' => 424242,
            'moh_drug_id' => 868686,
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        $request = $this->pendingRequest($category);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', MedicineRequest::STATUS_APPROVED);

        $request->refresh();

        // 🔴 المفتاح الحصري: أحدهما فقط
        $this->assertNotNull($request->approved_moh_medicine_id);
        $this->assertNull($request->approved_medicine_id);

        $mohRow = MohMedicine::find($request->approved_moh_medicine_id);

        // 🔴 رابط القسم بالمفتاح المستقر لا بالمعرّف الداخلي
        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $category->id,
            'moh_product_id' => $mohRow->moh_product_id,
            'moh_drug_id' => $mohRow->moh_drug_id,
            'source' => CategoryMedicineLink::SOURCE_ADMIN,
        ]);

        // الدواء الجسري يُنشأ من بيانات MOH، وسطر المخزون يحمل moh_medicine_id الداخلي
        $bridge = Medicine::where('trade_name', 'Panadol Extra')->firstOrFail();
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $request->pharmacy_id,
            'medicine_id' => $bridge->id,
            'moh_medicine_id' => $mohRow->id,
        ]);
    }

    public function test_approval_bumps_the_category_catalog_cache(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category);
        $admin = $this->admin();

        $before = CategoryCatalogCache::version();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertOk();

        $this->assertGreaterThan($before, CategoryCatalogCache::version());
    }

    // ══════════════════════════════════════════════════════════════════
    // الحالات غير الصالحة — ولا أي أثر جانبي
    // ══════════════════════════════════════════════════════════════════

    public function test_duplicate_approval_is_rejected_and_creates_no_duplicates(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request');

        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $request->pharmacy_id)->count());
        $this->assertSame(1, CategoryMedicineLink::where('category_id', $category->id)->count());
    }

    public function test_approving_a_non_pending_request_is_rejected(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category, ['status' => MedicineRequest::STATUS_REJECTED]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('request');

        $this->assertSame(
            MedicineRequest::STATUS_REJECTED,
            $request->fresh()->status,
            'الرفض يجب ألا يُحوَّل إلى اعتماد.'
        );
    }

    public function test_inactive_category_is_rejected_with_no_side_effects(): void
    {
        $category = $this->category();
        $category->update(['is_active' => false]);

        $request = $this->pendingRequest($category);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertDatabaseMissing('pharmacy_medicines', ['pharmacy_id' => $request->pharmacy_id]);
        $this->assertDatabaseMissing('category_medicine_links', ['category_id' => $category->id]);
        $this->assertDatabaseMissing('medicines', ['trade_name' => 'Panadol Extra']);
        $this->assertSame(MedicineRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_subcategory_from_another_category_is_rejected(): void
    {
        $category = $this->category();
        $other = $this->category();

        \App\Models\Subcategory::create([
            'category_id' => $other->id,
            'name_ar' => 'غير تابع',
            'name_en' => 'Foreign',
            'slug' => 'foreign-'.uniqid(),
            'is_active' => true,
        ]);

        $request = $this->pendingRequest($category, [
            'subcategory_id' => \App\Models\Subcategory::first()->id,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subcategory_id');

        $this->assertDatabaseCount('pharmacy_medicines', 0);
    }

    // ══════════════════════════════════════════════════════════════════
    // التفويض
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_approve(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category);

        $this->postJson(self::ENDPOINT."/{$request->id}/approve", [])->assertStatus(401);

        $this->assertSame(MedicineRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_non_admin_cannot_approve(): void
    {
        $category = $this->category();
        $request = $this->pendingRequest($category);
        $pharmacy = $this->userWithRole('pharmacy');

        $this->actingAs($pharmacy, 'sanctum')
            ->postJson(self::ENDPOINT."/{$request->id}/approve", [])
            ->assertStatus(403);

        $this->assertSame(MedicineRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertDatabaseCount('pharmacy_medicines', 0);
    }
}
