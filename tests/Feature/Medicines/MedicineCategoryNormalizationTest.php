<?php

namespace Tests\Feature\Medicines;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 11 — توحيد التصنيف على الـ11 قسمًا الحالية:
 *  - كل دواء = قسم واحد فقط
 *  - دواء جديد (إضافة يدوية / by-name) يتطلب قسمًا من القائمة المغلقة
 *  - لا Unknown Category، لا نص حر، لا قسم جديد
 */
class MedicineCategoryNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = \App\Models\Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function categoryId(string $slug): int
    {
        return (int) Category::where('slug', $slug)->value('id');
    }

    // ── القاعدة الأساسية: 11 قسمًا فقط ─────────────────────────────

    public function test_exactly_eleven_categories_exist_and_all_active(): void
    {
        $this->assertSame(11, Category::count());
        $this->assertSame(0, Category::where('is_active', false)->count());
        $this->assertSame(11, Category::active()->count());
    }

    public function test_every_category_id_in_links_belongs_to_the_existing_eleven(): void
    {
        $slugs = [
            'medicines', 'dental-care', 'first-aid', 'mother-baby',
            'skin-care-beauty', 'medical-supplies', 'eye-care',
            'health-safety', 'vitamins-supplements', 'veterinary', 'herbal',
        ];
        $ids = collect($slugs)->map(fn ($s) => $this->categoryId($s))->values()->all();
        $this->assertCount(11, $ids);

        $outOfEleven = CategoryMedicineLink::whereNotIn('category_id', $ids)->count();
        $this->assertSame(0, $outOfEleven, 'لا رابط يشير إلى قسم خارج القائمة المغلقة');
        $this->assertSame(0, CategoryMedicineLink::whereNull('category_id')->count());
    }

    public function test_new_local_medicine_gets_exactly_one_category(): void
    {
        // دواء محلي جديد يُربط بقسم واحد فقط — قاعدة «دواء واحد = قسم واحد».
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'SoleCat Drug',
                'active_ingredient' => 'Solozole',
                'category_id' => $this->categoryId('medicines'),
                'price' => 5,
                'quantity' => 3,
                'is_available' => 1,
            ])
            ->assertRedirect();

        $medicine = Medicine::where('trade_name', 'SoleCat Drug')->first();
        $this->assertNotNull($medicine);

        $links = CategoryMedicineLink::where('medicine_id', $medicine->id)->get();
        $this->assertSame(1, $links->count(), 'دواء محلي جديد يجب أن يملك قسمًا واحدًا فقط');
        $this->assertSame($this->categoryId('medicines'), (int) $links->first()->category_id);
    }

    // ── دواء جديد بدون قسم → مرفوض ───────────────────────────────

    public function test_web_manual_medicine_add_requires_category(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'NoCat Web Drug',
                'active_ingredient' => 'Xyzol',
                'price' => 5,
                'quantity' => 3,
                'is_available' => 1,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('medicines', ['trade_name' => 'NoCat Web Drug']);
    }

    public function test_web_manual_medicine_add_with_valid_category_creates_medicine_and_link(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'Cat Web Drug',
                'active_ingredient' => 'Yzomol',
                'category_id' => $this->categoryId('medicines'),
                'price' => 5,
                'quantity' => 3,
                'is_available' => 1,
            ])
            ->assertRedirect();

        $medicine = Medicine::where('trade_name', 'Cat Web Drug')->first();
        $this->assertNotNull($medicine);
        $this->assertDatabaseHas('category_medicine_links', [
            'medicine_id' => $medicine->id,
            'category_id' => $this->categoryId('medicines'),
        ]);
    }

    public function test_web_manual_medicine_add_with_invalid_category_rejected(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $this->actingAs($user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'BadCat Web Drug',
                'active_ingredient' => 'Xyzol',
                'category_id' => 999999,
                'price' => 5,
                'quantity' => 3,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('medicines', ['trade_name' => 'BadCat Web Drug']);
    }

    public function test_by_name_add_requires_category(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'NoCat Api Drug',
            'active_ingredient' => 'Mysteryol',
            'price' => 20,
            'quantity' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');

        $this->assertDatabaseMissing('medicines', ['trade_name' => 'NoCat Api Drug']);
    }

    public function test_by_name_add_with_valid_category_creates_link(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Cat Api Drug',
            'active_ingredient' => 'Mysteryol',
            'category_id' => $this->categoryId('veterinary'),
            'price' => 20,
            'quantity' => 7,
        ])->assertStatus(201);

        $medicine = Medicine::where('trade_name', 'Cat Api Drug')->first();
        $this->assertDatabaseHas('category_medicine_links', [
            'medicine_id' => $medicine->id,
            'category_id' => $this->categoryId('veterinary'),
        ]);
    }

    // ── subcategory يجب أن تنتمي للقسم المختار ────────────────────

    public function test_by_name_subcategory_of_wrong_category_rejected(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);

        $otherCat = Category::create([
            'name_ar' => 'قسم آخر', 'name_en' => 'Other '.uniqid(),
            'slug' => 'other-'.uniqid(), 'is_active' => true, 'sort_order' => 9,
        ]);
        $sub = Subcategory::create([
            'category_id' => $otherCat->id,
            'name_ar' => 'فرعي', 'name_en' => 'Sub '.uniqid(),
            'slug' => 'sub-'.uniqid(), 'group_key' => 'g', 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'SubMismatch Drug',
            'active_ingredient' => 'Mysteryol',
            'category_id' => $this->categoryId('herbal'),
            'subcategory_id' => $sub->id,
            'price' => 20,
            'quantity' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('subcategory_id');
    }

    // ── Patient endpoint: لا يظهر «دواء غير معروف» عند وجود السجل ───

    public function test_category_endpoint_returns_linked_local_medicine_with_real_name(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $medicine = Medicine::create([
            'trade_name' => 'REAL LOCAL MED',
            'active_ingredient' => 'RealGeneric',
        ]);

        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'medicine_id' => $medicine->id,
            'source' => 'admin',
            'confidence' => 100,
        ]);

        // مخزون متوفر حتى يظهر الدواء (قاعدة المتوفر افتراضياً في endpoint المريض)
        $pharmacy = \App\Models\Pharmacy::factory()->create(['is_active' => true]);
        \App\Models\PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $response = $this->getJson('/api/categories/'.$category->id.'/medicines');
        $response->assertOk()->assertJsonPath('pagination.total', 1);

        $names = collect($response->json('data', []))->map(fn ($d) => $d['trade_name'] ?? null)->all();
        $this->assertContains('REAL LOCAL MED', $names);
        $this->assertNotContains('دواء غير معروف', $names);
        $this->assertNotContains('Unknown Medicine', $names);
    }

    public function test_category_endpoint_never_returns_unknown_name_for_resolvable_moh_medicine(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $moh = \App\Models\MohMedicine::create([
            'trade_name' => 'REAL NAME MOH',
            'moh_product_id' => 91001,
            'generic_name' => 'RealGeneric',
        ]);

        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 91001,
            'source' => 'rules',
            'confidence' => 100,
        ]);

        // جسر محلي + مخزون متوفر (نفس نمط approval: medicine_id bridge + moh_medicine_id)
        $bridge = Medicine::create([
            'trade_name' => 'REAL NAME MOH',
            'active_ingredient' => 'RealGeneric',
        ]);
        $pharmacy = \App\Models\Pharmacy::factory()->create(['is_active' => true]);
        \App\Models\PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $bridge->id,
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $response = $this->getJson('/api/categories/'.$category->id.'/medicines');
        $response->assertOk();

        $names = collect($response->json('data', []))->map(fn ($d) => $d['trade_name'] ?? null)->all();
        $this->assertContains('REAL NAME MOH', $names);
        $this->assertNotContains('دواء غير معروف', $names);
        $this->assertNotContains('Unknown Medicine', $names);
    }
}
