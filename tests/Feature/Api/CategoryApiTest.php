<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Services\MedicineCatalogService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SubcategorySeeder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
        $this->seed(SubcategorySeeder::class);
    }

    private function categoryId(string $slug): int
    {
        return (int) Category::where('slug', $slug)->value('id');
    }

    private function subcategoryId(string $slug): int
    {
        return (int) \App\Models\Subcategory::where('slug', $slug)->value('id');
    }

    private function createMohMedicine(array $attributes = []): MohMedicine
    {
        return MohMedicine::create($attributes + [
            'trade_name' => 'CATMED 5mg',
            'moh_product_id' => 2001,
        ]);
    }

    /**
     * Patient DEFAULT: أي دواء مرتبط بقسم يجب أن يملك مخزوناً متوفراً
     * (صيدلية نشطة + is_available + quantity>0) ليظهر في مسارات الأقسام.
     */
    private function stockMoh(MohMedicine $moh, bool $available = true, int $quantity = 10): void
    {
        $medicine = Medicine::factory()->create();
        $user = User::factory()->create();
        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'ST-'.uniqid(),
            'pharmacy_name' => 'Stock Pharmacy '.uniqid(),
            'address' => 'Addr',
            'phone_number' => '0590000000',
            'region' => 'Region',
            'is_active' => true,
            'avg_rating' => 0,
        ]));
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $moh->id,
            'price' => 5,
            'quantity' => $quantity,
            'is_available' => $available,
        ]);
    }

    public function test_index_returns_all_active_categories_with_counts_and_image_field(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'name_ar', 'name_en', 'slug', 'image', 'is_active', 'sort_order', 'medicines_count']],
            ]);

        $data = $response->json('data');
        $this->assertCount(11, $data);
        $this->assertSame('medicines', $data[0]['slug']);

        foreach ($data as $item) {
            $this->assertArrayHasKey('medicines_count', $item);
            $this->assertArrayHasKey('image', $item);
            $this->assertTrue($item['is_active']);
        }
    }

    public function test_show_resolves_by_id_and_by_slug(): void
    {
        $id = $this->categoryId('medicines');

        $byId = $this->getJson("/api/categories/{$id}");
        $byId->assertOk()->assertJson(['success' => true]);
        $this->assertSame('medicines', $byId->json('data.slug'));
        $this->assertSame($id, $byId->json('data.id'));

        $bySlug = $this->getJson('/api/categories/medicines');
        $bySlug->assertOk()->assertJson(['success' => true]);
        $this->assertSame($id, $bySlug->json('data.id'));
    }

    public function test_show_unknown_category_returns_404(): void
    {
        $this->getJson('/api/categories/not-a-real-slug')
            ->assertStatus(404)
            ->assertJson(['success' => false]);

        $this->getJson('/api/categories/999999')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_category_seeder_restores_soft_deleted_default_category(): void
    {
        $category = Category::where('slug', 'herbal')->firstOrFail();
        $category->delete();

        $this->seed(CategorySeeder::class);

        $restored = Category::where('slug', 'herbal')->first();
        $this->assertNotNull($restored);
        $this->assertSame($category->id, $restored->id);
        $this->assertSame(11, Category::count());
    }

    public function test_inactive_category_is_hidden_from_index_and_show(): void
    {
        Category::where('slug', 'herbal')->update(['is_active' => false]);
        $herbalId = $this->categoryId('herbal');

        $data = $this->getJson('/api/categories')->json('data');
        $this->assertCount(10, $data);
        foreach ($data as $item) {
            $this->assertNotSame('herbal', $item['slug']);
        }

        $this->getJson('/api/categories/herbal')->assertStatus(404);
        $this->getJson("/api/categories/{$herbalId}")->assertStatus(404);
    }

    public function test_inactive_category_id_still_filters_public_medicines(): void
    {
        // قرار متعمّد: الفلتر لا يشترط is_active — قسم غير نشط يبقى يفلتر
        // (السلوك التاريخي محفوظ؛ إخفاء القسم يخصّ endpoints الأقسام المباشرة فقط).
        $category = Category::where('slug', 'herbal')->firstOrFail();
        $category->update(['is_active' => false]);
        $this->createMohMedicine(['trade_name' => 'LINKED INACTIVE MED', 'moh_product_id' => 8101]);
        $this->createMohMedicine(['trade_name' => 'PLAIN ACTIVE MED', 'moh_product_id' => 8102]);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 8101,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $response = $this->getJson('/api/medicines?category_id='.$category->id)->assertOk();

        $this->assertSame(1, $response->json('pagination.total'));
        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains('LINKED INACTIVE MED', $names);
        $this->assertNotContains('PLAIN ACTIVE MED', $names);
    }

    public function test_category_medicines_expose_local_medicine_id_when_name_matches(): void
    {
        $category = $this->categoryId('medicines');
        $this->createMohMedicine(['trade_name' => 'BRIDGE MED 5mg', 'moh_product_id' => 8301]);
        $this->stockMoh(MohMedicine::where('moh_product_id', 8301)->first());
        $local = Medicine::factory()->create(['trade_name' => 'BRIDGE MED 5mg']);
        CategoryMedicineLink::create([
            'category_id' => $category,
            'moh_product_id' => 8301,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $response = $this->getJson('/api/categories/medicines/medicines')->assertOk();

        $row = collect($response->json('data'))->firstWhere('trade_name', 'BRIDGE MED 5mg');
        $this->assertNotNull($row);
        $this->assertSame($local->id, $row['medicine_id']);
    }

    public function test_category_medicines_medicine_id_is_null_without_local_match(): void
    {
        $category = $this->categoryId('medicines');
        $this->createMohMedicine(['trade_name' => 'NO LOCAL TWIN 5mg', 'moh_product_id' => 8302]);
        $this->stockMoh(MohMedicine::where('moh_product_id', 8302)->first());
        CategoryMedicineLink::create([
            'category_id' => $category,
            'moh_product_id' => 8302,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $response = $this->getJson('/api/categories/medicines/medicines')->assertOk();

        $row = collect($response->json('data'))->firstWhere('trade_name', 'NO LOCAL TWIN 5mg');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('medicine_id', $row);
        $this->assertNull($row['medicine_id']);
    }

    public function test_admin_category_changes_invalidate_warm_public_category_cache(): void
    {
        $this->getJson('/api/categories')->assertOk();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('categories.store'), [
            'name_ar' => 'قسم فوري',
            'name_en' => 'Immediate Category',
            'is_active' => '1',
        ])->assertRedirect(route('categories.index'));

        $created = $this->getJson('/api/categories')->assertOk();
        $this->assertContains('immediate-category', collect($created->json('data'))->pluck('slug')->all());

        $category = Category::where('slug', 'immediate-category')->firstOrFail();
        $this->actingAs($admin)->patch(route('categories.toggleStatus', $category))->assertRedirect();

        $hidden = $this->getJson('/api/categories')->assertOk();
        $this->assertNotContains('immediate-category', collect($hidden->json('data'))->pluck('slug')->all());
    }

    public function test_index_image_url_passes_external_url_through(): void
    {
        Category::where('slug', 'herbal')->update(['image' => 'https://cdn.example.com/herbal.png']);

        $data = $this->getJson('/api/categories')->json('data');
        $herbal = collect($data)->firstWhere('slug', 'herbal');

        $this->assertSame('https://cdn.example.com/herbal.png', $herbal['image']);
    }

    public function test_category_medicines_are_paginated_and_linked_rows_returned(): void
    {
        $category = $this->categoryId('medicines');

        $alpha = $this->createMohMedicine(['trade_name' => 'ALPHA MED 5mg', 'moh_product_id' => 2001]);
        $beta = $this->createMohMedicine(['trade_name' => 'BETA MED 5mg', 'moh_product_id' => 2002]);
        $gamma = $this->createMohMedicine(['trade_name' => 'GAMMA OTHER 5mg', 'moh_product_id' => 2003]);
        $this->stockMoh($alpha);
        $this->stockMoh($beta);
        // gamma is NOT stocked — should be excluded by availability filter.

        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $response = $this->getJson('/api/categories/medicines/medicines');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'medicine_id', 'trade_name', 'generic_name', 'dosage_form', 'product_class']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);

        $this->assertSame(2, $response->json('pagination.total'));
        $this->assertSame(20, $response->json('pagination.per_page'));

        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains('ALPHA MED 5mg', $names);
        $this->assertContains('BETA MED 5mg', $names);
        $this->assertNotContains('GAMMA OTHER 5mg', $names);
    }

    public function test_category_medicines_respect_per_page(): void
    {
        $category = $this->categoryId('medicines');

        $alpha = $this->createMohMedicine(['trade_name' => 'ALPHA MED 5mg', 'moh_product_id' => 2001]);
        $beta = $this->createMohMedicine(['trade_name' => 'BETA MED 5mg', 'moh_product_id' => 2002]);
        $this->stockMoh($alpha);
        $this->stockMoh($beta);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $response = $this->getJson('/api/categories/medicines/medicines?per_page=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.per_page'));
        $this->assertSame(2, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.last_page'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_category_medicines_support_q_search_filter(): void
    {
        $category = $this->categoryId('medicines');

        $pana = $this->createMohMedicine(['trade_name' => 'PANARELief 500mg', 'moh_product_id' => 2001]);
        $ibu = $this->createMohMedicine(['trade_name' => 'IBULief 400mg', 'moh_product_id' => 2002]);
        $this->stockMoh($pana);
        $this->stockMoh($ibu);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $response = $this->getJson('/api/categories/medicines/medicines?q=PANA');

        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.total'));

        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains('PANARELief 500mg', $names);
        $this->assertNotContains('IBULief 400mg', $names);
    }

    public function test_category_medicines_unknown_category_returns_404(): void
    {
        $this->getJson('/api/categories/no-such-cat/medicines')->assertStatus(404);
    }

    public function test_dosage_forms_endpoint_returns_canonical_list(): void
    {
        $response = $this->getJson('/api/dosage-forms');

        $response->assertOk()->assertJson(['success' => true]);

        $facets = $response->json('data');
        $this->assertNotEmpty($facets);
        $this->assertContains('حبوب', $facets);
        $this->assertContains('بخاخ', $facets);
    }

    public function test_medicines_index_filters_by_category_via_stable_keys(): void
    {
        $category = $this->categoryId('medicines');

        $this->createMohMedicine(['trade_name' => 'LINKED MED 1', 'moh_product_id' => 3001]);
        $this->createMohMedicine(['trade_name' => 'UNLINKED MED 1', 'moh_product_id' => 3002]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 3001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $filtered = $this->getJson('/api/medicines?category_id='.$category);
        $filtered->assertOk();
        $this->assertSame(1, $filtered->json('pagination.total'));
        $names = collect($filtered->json('data'))->pluck('trade_name')->all();
        $this->assertContains('LINKED MED 1', $names);
        $this->assertNotContains('UNLINKED MED 1', $names);

        $unfiltered = $this->getJson('/api/medicines');
        $unfiltered->assertOk();
        $this->assertSame(2, $unfiltered->json('pagination.total'));
    }

    public function test_medicines_index_ignores_unknown_category_id_silently(): void
    {
        // category_id غير صالح → يُتجاهل بصمت (200 + النتائج العادية)
        // بدل 422 — تجنّب redirect-back لعملاء بدون Accept: application/json
        $this->createMohMedicine(['trade_name' => 'PLAINMED 1', 'moh_product_id' => 6001]);

        $response = $this->getJson('/api/medicines?category_id=424242')->assertOk();
        $this->assertSame(1, $response->json('pagination.total'));
    }

    public function test_medicines_index_exposes_local_medicine_id_when_name_matches(): void
    {
        $this->createMohMedicine(['trade_name' => 'CATALOG BRIDGE 5mg', 'moh_product_id' => 8401]);
        $local = Medicine::factory()->create(['trade_name' => 'CATALOG BRIDGE 5mg']);

        $response = $this->getJson('/api/medicines')->assertOk();

        $row = collect($response->json('data'))->firstWhere('trade_name', 'CATALOG BRIDGE 5mg');
        $this->assertNotNull($row);
        // ملاحظة: لا نؤكد اختلاف id عن medicine_id — الجدولان مستقلان وقد يتصادف
        // الرقمان (كلاهما 1 في قاعدة اختبار فارغة)، وهذا بالضبط سبب الحاجة للحقل.
        $this->assertSame($local->id, $row['medicine_id']);
    }

    public function test_medicines_index_medicine_id_is_null_without_local_match(): void
    {
        $this->createMohMedicine(['trade_name' => 'CATALOG NO TWIN 5mg', 'moh_product_id' => 8402]);

        $response = $this->getJson('/api/medicines')->assertOk();

        $row = collect($response->json('data'))->firstWhere('trade_name', 'CATALOG NO TWIN 5mg');
        $this->assertNotNull($row);
        $this->assertArrayHasKey('medicine_id', $row);
        $this->assertNull($row['medicine_id']);
    }

    public function test_category_browse_drill_down_chain_reaches_real_medicine_detail(): void
    {
        // سلسلة الإنتاج كاملة: أدمن يربط دواء وزارة الصحة بقسم → صيدلية تضيف نفس
        // الدواء من كتالوج الوزارة (يُنشأ الدواء المحلي) → العميل يستعرض القسم →
        // يمرّر medicine_id لـ endpoint التفاصيل. هذا هو الغرض الفعلي من الحقل.
        $category = Category::where('slug', 'medicines')->firstOrFail();
        $moh = $this->createMohMedicine([
            'trade_name' => 'CHAIN MED 500mg',
            'moh_product_id' => 8501,
            'moh_drug_id' => 9501,
        ]);
        $this->stockMoh($moh);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 8501,
            'moh_drug_id' => 9501,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $local = app(MedicineCatalogService::class)->findOrCreateFromMoh($moh);

        $browse = $this->getJson('/api/categories/medicines/medicines')->assertOk();
        $row = collect($browse->json('data'))->firstWhere('trade_name', 'CHAIN MED 500mg');
        $this->assertNotNull($row);
        $this->assertSame(
            $local->id,
            $row['medicine_id'],
            'قاعدة المطابقة في الـ API يجب أن تطابق قاعدة MedicineCatalogService'
        );

        $detail = $this->getJson('/api/medicines/'.$row['medicine_id'])->assertOk();
        $this->assertSame($local->id, $detail->json('data.id'));
        $this->assertSame('CHAIN MED 500mg', $detail->json('data.trade_name'));

        // ونفس المعرّف يعمل على مسار التوفر (الصيدليات المتوفر بها الدواء)
        $this->getJson('/api/medicines/'.$row['medicine_id'].'/pharmacies')->assertOk();
    }

    public function test_medicines_index_filters_by_canonical_dosage_form(): void
    {
        $category = $this->categoryId('medicines');

        $this->createMohMedicine(['trade_name' => 'TABMED 1', 'moh_product_id' => 5001, 'dosage_form' => 'Film coated tablet']);
        $this->createMohMedicine(['trade_name' => 'SPRAYMED 1', 'moh_product_id' => 5002, 'dosage_form' => 'Nasal spray']);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 5001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 5002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $tablets = $this->getJson('/api/medicines?dosage_form='.urlencode('حبوب'));
        $tablets->assertOk();
        $this->assertSame(1, $tablets->json('pagination.total'));
        $names = collect($tablets->json('data'))->pluck('trade_name')->all();
        $this->assertContains('TABMED 1', $names);
        $this->assertNotContains('SPRAYMED 1', $names);

        $unknownForm = $this->getJson('/api/medicines?dosage_form=weird-unknown-form');
        $unknownForm->assertOk();
        $this->assertSame(2, $unknownForm->json('pagination.total'));
    }

    public function test_medicines_index_cache_is_consistent_for_identical_category_requests(): void
    {
        $category = $this->categoryId('medicines');

        $this->createMohMedicine(['trade_name' => 'CACHE MED 1', 'moh_product_id' => 6001]);
        $this->createMohMedicine(['trade_name' => 'CACHE MED 2', 'moh_product_id' => 6002]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 6001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $first = $this->getJson('/api/medicines?category_id='.$category);
        $second = $this->getJson('/api/medicines?category_id='.$category);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('pagination'), $second->json('pagination'));
        $this->assertSame(
            collect($first->json('data'))->pluck('trade_name')->sort()->values()->all(),
            collect($second->json('data'))->pluck('trade_name')->sort()->values()->all()
        );
        $this->assertSame(1, $second->json('pagination.total'));
    }

    public function test_category_medicines_cache_serves_identical_results_on_repeat(): void
    {
        $category = $this->categoryId('medicines');

        $cached = $this->createMohMedicine(['trade_name' => 'CACHED CAT MED', 'moh_product_id' => 7001]);
        $this->stockMoh($cached);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 7001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $first = $this->getJson('/api/categories/medicines/medicines');
        $second = $this->getJson('/api/categories/medicines/medicines');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('pagination.total'), $second->json('pagination.total'));
        $this->assertSame(1, $second->json('pagination.total'));
    }

    public function test_admin_attach_invalidates_warm_category_medicine_caches(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();
        $first = $this->createMohMedicine(['trade_name' => 'FIRST CACHE MED', 'moh_product_id' => 8201, 'moh_drug_id' => 9201]);
        $second = $this->createMohMedicine(['trade_name' => 'SECOND CACHE MED', 'moh_product_id' => 8202, 'moh_drug_id' => 9202]);
        $this->stockMoh($first);
        $this->stockMoh($second);
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => 8201,
            'moh_drug_id' => 9201,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->getJson('/api/categories/medicines/medicines')->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/medicines?category_id='.$category->id)->assertJsonPath('pagination.total', 1);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('categories.medicines.attach', $category), [
            'type' => 'moh',
            'moh_product_id' => 8202,
            'moh_drug_id' => 9202,
        ])->assertRedirect();

        $this->getJson('/api/categories/medicines/medicines')->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/medicines?category_id='.$category->id)->assertJsonPath('pagination.total', 2);
    }

    public function test_cache_store_is_array_in_testing(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertInstanceOf(\Illuminate\Cache\ArrayStore::class, Cache::store()->getStore());
    }

    public function test_index_returns_subcategory_medicine_counts(): void
    {
        $medCat = $this->categoryId('medicines');
        $hairSub = $this->subcategoryId('cough-sore-throat');

        $moh1 = $this->createMohMedicine(['trade_name' => 'SUBCAT COUNT MED 1', 'moh_product_id' => 8701]);
        $moh2 = $this->createMohMedicine(['trade_name' => 'SUBCAT COUNT MED 2', 'moh_product_id' => 8702]);
        $this->stockMoh($moh1);
        $this->stockMoh($moh2);

        CategoryMedicineLink::create(['category_id' => $medCat, 'subcategory_id' => $hairSub, 'moh_product_id' => 8701, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $medCat, 'subcategory_id' => $hairSub, 'moh_product_id' => 8702, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $data = $this->getJson('/api/categories')->assertOk()->json('data');
        $medCatData = collect($data)->firstWhere('slug', 'medicines');
        $this->assertNotNull($medCatData);

        $subcat = collect($medCatData['subcategories'])->firstWhere('id', $hairSub);
        $this->assertNotNull($subcat);
        $this->assertSame(2, $subcat['medicines_count']);

        // other subcategories under medicines with no links should be 0
        $coldSub = $this->subcategoryId('cold-flu');
        $coldSubData = collect($medCatData['subcategories'])->firstWhere('id', $coldSub);
        $this->assertNotNull($coldSubData);
        $this->assertSame(0, $coldSubData['medicines_count']);
    }

    public function test_show_returns_subcategory_medicine_counts(): void
    {
        $medCat = $this->categoryId('medicines');
        $painSub = $this->subcategoryId('pain-headache');

        $moh = $this->createMohMedicine(['trade_name' => 'PAIN MED 1', 'moh_product_id' => 8801]);
        $this->stockMoh($moh);
        CategoryMedicineLink::create(['category_id' => $medCat, 'subcategory_id' => $painSub, 'moh_product_id' => 8801, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $data = $this->getJson("/api/categories/{$medCat}")->assertOk()->json('data');

        $subcat = collect($data['subcategories'])->firstWhere('id', $painSub);
        $this->assertSame(1, $subcat['medicines_count']);
    }

    public function test_subcategory_medicine_counts_do_not_affect_parent_category_count(): void
    {
        $vitCat = $this->categoryId('vitamins-supplements');
        $proteinSub = $this->subcategoryId('protein-supplements');

        $moh = $this->createMohMedicine(['trade_name' => 'VITALINK', 'moh_product_id' => 8901]);
        $this->stockMoh($moh);
        CategoryMedicineLink::create(['category_id' => $vitCat, 'subcategory_id' => $proteinSub, 'moh_product_id' => 8901, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $data = $this->getJson('/api/categories')->assertOk()->json('data');
        $vitCatData = collect($data)->firstWhere('slug', 'vitamins-supplements');

        $subcat = collect($vitCatData['subcategories'])->firstWhere('id', $proteinSub);
        $this->assertSame(1, $subcat['medicines_count']);
        $this->assertSame(1, $vitCatData['medicines_count']);
    }

    public function test_subcategory_medicines_endpoint_still_works_after_count_fix(): void
    {
        $medCat = $this->categoryId('medicines');
        $coughSub = $this->subcategoryId('cough-sore-throat');

        $coughA = $this->createMohMedicine(['trade_name' => 'COUGH MED A', 'moh_product_id' => 9001]);
        $coughB = $this->createMohMedicine(['trade_name' => 'COUGH MED B', 'moh_product_id' => 9002]);
        $this->stockMoh($coughA);
        $this->stockMoh($coughB);
        CategoryMedicineLink::create(['category_id' => $medCat, 'subcategory_id' => $coughSub, 'moh_product_id' => 9001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $medCat, 'subcategory_id' => $coughSub, 'moh_product_id' => 9002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $response = $this->getJson('/api/categories/medicines/medicines?subcategory_id='.$coughSub)->assertOk();
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_filters_endpoint_returns_correct_subcategory_counts(): void
    {
        $filterMed = $this->createMohMedicine(['trade_name' => 'FILTER COUNT MED', 'moh_product_id' => 9101]);
        $this->stockMoh($filterMed);
        CategoryMedicineLink::create(['category_id' => $this->categoryId('medicines'), 'subcategory_id' => $this->subcategoryId('cough-sore-throat'), 'moh_product_id' => 9101, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $data = $this->getJson('/api/medicine-filters')->assertOk()->json('data');
        $coughSub = collect($data['categories'][0]['subcategories'])->firstWhere('slug', 'cough-sore-throat');
        $this->assertSame(1, $coughSub['medicines_count']);
    }
}
