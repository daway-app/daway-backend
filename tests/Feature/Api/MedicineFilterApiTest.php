<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Subcategory;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SubcategorySeeder;
use Tests\TestCase;

class MedicineFilterApiTest extends TestCase
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
        return (int) Subcategory::where('slug', $slug)->value('id');
    }

    private function moh(array $attributes = []): MohMedicine
    {
        static $seq = 70000;
        $seq++;

        return MohMedicine::create($attributes + [
            'trade_name' => 'FILTER MED '.$seq,
            'moh_product_id' => $seq,
        ]);
    }

    private function stockMedicine(MohMedicine $moh): void
    {
        $user = User::factory()->create();
        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'STOCK-'.uniqid(),
            'pharmacy_name' => 'Stock Pharmacy '.uniqid(),
            'address' => 'Addr',
            'phone_number' => '0590000000',
            'region' => 'Region',
            'is_active' => true,
            'avg_rating' => 0,
        ]));
        $medicine = Medicine::factory()->create(['trade_name' => 'STOCKED '.$moh->id]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => 10,
            'is_available' => true,
        ]);
    }

    // ───────────────────────── فلتر القسم الفرعي ─────────────────────────

    public function test_medicines_index_filters_by_subcategory_id_and_slug(): void
    {
        $hairId = $this->subcategoryId('hair-vitamins');
        $proteinId = $this->subcategoryId('protein-supplements');
        $vitaminsCat = $this->categoryId('vitamins-supplements');

        $this->moh(['trade_name' => 'HAIR BOOST', 'moh_product_id' => 71001]);
        $this->moh(['trade_name' => 'PROTEIN WHEY', 'moh_product_id' => 71002]);

        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat, 'subcategory_id' => $hairId,
            'moh_product_id' => 71001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
        ]);
        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat, 'subcategory_id' => $proteinId,
            'moh_product_id' => 71002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
        ]);

        $byId = $this->getJson('/api/medicines?subcategory_id='.$hairId)->assertOk();
        $this->assertSame(1, $byId->json('pagination.total'));
        $this->assertSame('HAIR BOOST', $byId->json('data.0.trade_name'));

        $bySlug = $this->getJson('/api/medicines?subcategory=protein-supplements')->assertOk();
        $this->assertSame(1, $bySlug->json('pagination.total'));
        $this->assertSame('PROTEIN WHEY', $bySlug->json('data.0.trade_name'));
    }

    public function test_subcategory_filter_does_not_leak_across_categories(): void
    {
        // قسم فرعي تابع للفيتامينات + تمرير category_id لقسم آخر ⇒ لا نتائج
        $hairId = $this->subcategoryId('hair-vitamins');
        $dentalCat = $this->categoryId('dental-care');

        $this->moh(['trade_name' => 'HAIR ONLY', 'moh_product_id' => 72001]);
        CategoryMedicineLink::create([
            'category_id' => $this->categoryId('vitamins-supplements'), 'subcategory_id' => $hairId,
            'moh_product_id' => 72001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
        ]);

        $response = $this->getJson('/api/medicines?category_id='.$dentalCat.'&subcategory_id='.$hairId)->assertOk();

        $this->assertSame(0, $response->json('pagination.total'));
    }

    public function test_unknown_subcategory_is_ignored_silently(): void
    {
        $this->moh(['trade_name' => 'PLAIN SUB MED', 'moh_product_id' => 73001]);

        $response = $this->getJson('/api/medicines?subcategory_id=999999')->assertOk();

        // لا 422 ولا تصفير — تُتجاهل الفلترة
        $this->assertSame(1, $response->json('pagination.total'));
    }

    public function test_subcategory_allows_same_medicine_in_two_subcategories(): void
    {
        // نفس الدواء في قسمين فرعيين تحت نفس القسم الرئيسي — كان مستحيلاً
        // قبل توسيع الفهرس الفريد ليشمل subcategory_id
        $vitaminsCat = $this->categoryId('vitamins-supplements');
        $this->moh(['trade_name' => 'MULTI PURPOSE', 'moh_product_id' => 74001]);

        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat, 'subcategory_id' => $this->subcategoryId('hair-vitamins'),
            'moh_product_id' => 74001, 'source' => 'rules', 'confidence' => 75, 'needs_review' => false,
        ]);
        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat, 'subcategory_id' => $this->subcategoryId('skin-vitamins'),
            'moh_product_id' => 74001, 'source' => 'rules', 'confidence' => 75, 'needs_review' => false,
        ]);

        $this->assertSame(2, CategoryMedicineLink::where('moh_product_id', 74001)->count());

        foreach (['hair-vitamins', 'skin-vitamins'] as $slug) {
            $this->getJson('/api/medicines?subcategory='.$slug)
                ->assertOk()
                ->assertJsonPath('pagination.total', 1);
        }
    }

    // ───────────────────────── الفلترة عبر مسار الأقسام ─────────────────────────

    public function test_category_medicines_endpoint_supports_all_new_filters(): void
    {
        $vitaminsCat = $this->categoryId('vitamins-supplements');
        $hairId = $this->subcategoryId('hair-vitamins');

        $syrup = $this->moh(['trade_name' => 'HAIR SYRUP', 'moh_product_id' => 75001, 'dosage_form' => 'Oral syrup']);
        $tablet = $this->moh(['trade_name' => 'HAIR TABLET', 'moh_product_id' => 75002, 'dosage_form' => 'Film coated tablet']);

        $this->stockMedicine($syrup);
        $this->stockMedicine($tablet);

        foreach ([75001, 75002] as $pid) {
            CategoryMedicineLink::create([
                'category_id' => $vitaminsCat, 'subcategory_id' => $hairId,
                'moh_product_id' => $pid, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
            ]);
        }

        $bySub = $this->getJson("/api/categories/{$vitaminsCat}/medicines?subcategory={$hairId}")->assertOk();
        $this->assertSame(2, $bySub->json('pagination.total'), 'Should have 2 medicines in subcategory');

        $byForm = $this->getJson("/api/categories/{$vitaminsCat}/medicines?dosage_form=".urlencode('حبوب'))->assertOk();
        $this->assertSame(1, $byForm->json('pagination.total'), 'Should have 1 tablet medicine');
        $this->assertSame('HAIR TABLET', $byForm->json('data.0.trade_name'));

        $combined = $this->getJson("/api/categories/{$vitaminsCat}/medicines?subcategory={$hairId}&dosage_form=".urlencode('شراب'))->assertOk();
        $this->assertSame(1, $combined->json('pagination.total'), 'Should have 1 syrup medicine');
        $this->assertSame('HAIR SYRUP', $combined->json('data.0.trade_name'));

        $empty = $this->getJson("/api/categories/{$vitaminsCat}/medicines?subcategory={$hairId}&dosage_form=".urlencode('بخاخ'))->assertOk();
        $this->assertSame(0, $empty->json('pagination.total'));
    }

    public function test_category_medicines_subcategory_must_belong_to_that_category(): void
    {
        $hairId = $this->subcategoryId('hair-vitamins');

        $this->moh(['trade_name' => 'CROSS SUB MED', 'moh_product_id' => 76001]);
        CategoryMedicineLink::create([
            'category_id' => $this->categoryId('vitamins-supplements'), 'subcategory_id' => $hairId,
            'moh_product_id' => 76001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
        ]);

        // قسم فرعي لا ينتمي لهذا القسم الرئيسي ⇒ يُتجاهل (صفر نتيجة لأن باقي الفلتر يمنع)
        $this->getJson('/api/categories/dental-care/medicines?subcategory='.$hairId)
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    // ───────────────────────── endpoint الفلاتر ─────────────────────────

    public function test_filters_endpoint_returns_categories_subcategories_and_dosage_forms(): void
    {
        $response = $this->getJson('/api/medicine-filters');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'categories' => [['id', 'name_ar', 'name_en', 'slug', 'medicines_count', 'subcategories']],
                    'dosage_forms' => [['value', 'name_ar', 'medicines_count']],
                ],
            ]);

        $categories = collect($response->json('data.categories'));
        $vitamins = $categories->firstWhere('slug', 'vitamins-supplements');
        $this->assertNotNull($vitamins);

        // فلاتر الواجهة الفعلية: فيتامينات الشعر، مكملات البروتين ...
        $subSlugs = collect($vitamins['subcategories'])->pluck('slug')->all();
        $this->assertContains('hair-vitamins', $subSlugs);
        $this->assertContains('protein-supplements', $subSlugs);

        $groups = collect($vitamins['subcategories'])->pluck('group_key')->unique()->values()->all();
        $this->assertContains('vitamins', $groups);
        $this->assertContains('supplements', $groups);

        $dosageForms = collect($response->json('data.dosage_forms'))->pluck('value')->all();
        $this->assertContains('حبوب', $dosageForms);
        $this->assertContains('شراب', $dosageForms);
        $this->assertContains('بخاخ', $dosageForms);
        $this->assertContains('كريم', $dosageForms);
    }

    public function test_filters_endpoint_has_no_age_groups_key(): void
    {
        // فلتر الفئة العمرية أُلغي: لا مفاتيح ولا facets في الرد
        $data = $this->getJson('/api/medicine-filters')->json('data');

        $this->assertArrayNotHasKey('age_groups', $data);
    }

    public function test_medicines_index_ignores_age_group_param(): void
    {
        // المعامل لم يعد موجوداً في العقد — يُتجاهل بصمت ولا يفلتر شيئاً
        $this->moh(['trade_name' => 'AGE IGNORED A', 'moh_product_id' => 79001]);
        $this->moh(['trade_name' => 'AGE IGNORED B', 'moh_product_id' => 79002]);

        $response = $this->getJson('/api/medicines?age_group=kids')->assertOk();

        $this->assertSame(2, $response->json('pagination.total'));

        // ولا يظهر age_group في صف الرد
        $this->assertArrayNotHasKey('age_group', $response->json('data.0'));
    }

    public function test_filters_endpoint_counts_match_actual_filtering(): void
    {
        $vitaminsCat = $this->categoryId('vitamins-supplements');
        $hairId = $this->subcategoryId('hair-vitamins');

        $hairMed = $this->moh(['trade_name' => 'COUNT HAIR', 'moh_product_id' => 77001]);
        $otherMed = $this->moh(['trade_name' => 'COUNT OTHER', 'moh_product_id' => 77002]);

        $this->stockMedicine($hairMed);

        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat, 'subcategory_id' => $hairId,
            'moh_product_id' => 77001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false,
        ]);

        $facets = collect($this->getJson('/api/medicine-filters')->json('data.categories'))
            ->firstWhere('slug', 'vitamins-supplements');
        $hairFacet = collect($facets['subcategories'])->firstWhere('slug', 'hair-vitamins');
        $this->assertSame(1, $hairFacet['medicines_count']);

        $actual = $this->getJson('/api/medicines?subcategory='.$hairFacet['id'])->assertOk();
        $this->assertSame($hairFacet['medicines_count'], $actual->json('pagination.total'));
        $this->assertSame('COUNT HAIR', $actual->json('data.0.trade_name'));
    }

    public function test_filters_endpoint_excludes_inactive_categories_and_subcategories(): void
    {
        Category::where('slug', 'herbal')->update(['is_active' => false]);
        Subcategory::where('slug', 'hair-vitamins')->update(['is_active' => false]);

        $categories = collect($this->getJson('/api/medicine-filters')->json('data.categories'));

        $this->assertNotContains('herbal', $categories->pluck('slug')->all());

        $vitamins = $categories->firstWhere('slug', 'vitamins-supplements');
        $this->assertNotContains('hair-vitamins', collect($vitamins['subcategories'])->pluck('slug')->all());
        $this->assertContains('protein-supplements', collect($vitamins['subcategories'])->pluck('slug')->all());
    }

    // ───────────────────────── توافق خلفي ─────────────────────────

    public function test_categories_index_exposes_subcategories(): void
    {
        $data = collect($this->getJson('/api/categories')->json('data'));

        $vitamins = $data->firstWhere('slug', 'vitamins-supplements');
        $this->assertArrayHasKey('subcategories', $vitamins);
        $this->assertNotEmpty($vitamins['subcategories']);
        $this->assertArrayHasKey('group_key', $vitamins['subcategories'][0]);
    }

    public function test_existing_unfiltered_behaviour_is_unchanged(): void
    {
        // لا فلاتر ⇒ نفس الشكل والنتائج تماماً كما قبل التوسيع
        $this->moh(['trade_name' => 'ZETA UNFILTERED', 'moh_product_id' => 78001]);
        $this->moh(['trade_name' => 'ALPHA UNFILTERED', 'moh_product_id' => 78002]);

        $response = $this->getJson('/api/medicines')->assertOk();

        $response->assertJsonStructure([
            'success', 'message',
            'data' => [['id', 'medicine_id', 'trade_name', 'generic_name', 'dosage_form', 'product_class']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);
        $this->assertSame(2, $response->json('pagination.total'));
        // الترتيب الأبجدي محفوظ
        $this->assertSame('ALPHA UNFILTERED', $response->json('data.0.trade_name'));
    }

    public function test_subcategory_seeder_is_idempotent_and_restores_soft_deleted(): void
    {
        $before = Subcategory::count();
        $this->seed(SubcategorySeeder::class);
        $this->assertSame($before, Subcategory::count());

        $sub = Subcategory::where('slug', 'hair-vitamins')->firstOrFail();
        $sub->delete();
        $this->seed(SubcategorySeeder::class);

        $restored = Subcategory::where('slug', 'hair-vitamins')->first();
        $this->assertNotNull($restored);
        $this->assertSame($sub->id, $restored->id);
    }
}
