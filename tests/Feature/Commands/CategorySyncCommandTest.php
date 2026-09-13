<?php

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Support\CategoryCatalogCache;
use App\Support\MohCategorySync;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CategorySyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'storage/app/testing/fixtures/categorized_small.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    private function categoryId(string $slug): int
    {
        return (int) Category::where('slug', $slug)->value('id');
    }

    private function linkFor(int $productId, string $slug): ?CategoryMedicineLink
    {
        return CategoryMedicineLink::query()
            ->where('category_id', $this->categoryId($slug))
            ->where('moh_product_id', $productId)
            ->first();
    }

    public function test_sync_creates_links_with_metadata_from_json(): void
    {
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        // row 1: AMOXIDRUG → medicines (product_class, 90, no review)
        $link = $this->linkFor(1001, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame('product_class', $link->source);
        $this->assertSame(90, $link->confidence);
        $this->assertFalse($link->needs_review);
        $this->assertSame(11, (int) $link->moh_drug_id);

        // row 2: SKINGLOW → personal-care-and-beauty maps to skin-care-beauty (alias)
        $link = $this->linkFor(1002, 'skin-care-beauty');
        $this->assertNotNull($link, 'alias map should map personal-care-and-beauty → skin-care-beauty');
        $this->assertSame('rules', $link->source);
        $this->assertSame(84, $link->confidence);
    }

    public function test_sync_is_idempotent_on_second_run(): void
    {
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        $countFirst = CategoryMedicineLink::count();
        $this->assertSame(7, $countFirst, '6 rows: row1=1, row2=1, row3=2, row5=2, row6=1 = 7 links');

        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        $this->assertSame($countFirst, CategoryMedicineLink::count(), 'idempotent — no duplicates');
    }

    public function test_fresh_deletes_non_admin_links_but_preserves_admin(): void
    {
        $medicinesId = $this->categoryId('medicines');

        // أول sync بدون admin link
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();
        $countAfterFirst = CategoryMedicineLink::count();

        // الآن نضيف admin link — هذا لا يوجد في الـJSON ويجب أن يبقى بعد --fresh
        $adminLink = CategoryMedicineLink::create([
            'category_id' => $medicinesId,
            'moh_product_id' => 9999,
            'moh_drug_id' => 9999,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        // re-sync بـfresh: يحذف 7 non-admin، يُنشئ 7، ويبقى admin
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
            '--fresh' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('category_medicine_links', [
            'id' => $adminLink->id,
            'source' => 'admin',
            'moh_product_id' => 9999,
        ]);

        // admin link واحد + 7 من الـJSON = 8 = $countAfterFirst + 1
        $this->assertSame($countAfterFirst + 1, CategoryMedicineLink::count());
    }

    public function test_admin_link_not_overwritten_even_when_json_has_higher_confidence(): void
    {
        $medicinesId = $this->categoryId('medicines');

        CategoryMedicineLink::create([
            'category_id' => $medicinesId,
            'moh_product_id' => 1001,  // AMOXIDRUG — موجود في الـJSON بثقة 90
            'moh_drug_id' => 11,
            'source' => 'admin',
            'confidence' => 50,  // أقل من 90
            'needs_review' => false,
        ]);

        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        $link = $this->linkFor(1001, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame('admin', $link->source, 'admin source must be preserved');
        $this->assertSame(50, $link->confidence, 'admin confidence must not be overwritten');
    }

    public function test_unknown_json_slug_is_skipped_without_creating_category(): void
    {
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        // row 4 (RECALL ITEM X) عندها slug="unknown-future-category" — لازم تُتجاهَل
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 1004)->count());
        $this->assertSame(0, Category::where('slug', 'unknown-future-category')->count());
    }

    public function test_dry_run_does_not_write_to_db(): void
    {
        $before = CategoryMedicineLink::count();
        $this->assertSame(0, $before);

        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count(), 'dry-run must not create any links');
    }

    public function test_needs_review_links_are_preserved_with_correct_flag(): void
    {
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        // 3 needs_review links: row5 x2 + row6 x1
        $this->assertSame(3, CategoryMedicineLink::where('needs_review', true)->count());

        $vatica1 = $this->linkFor(19837, 'skin-care-beauty');
        $this->assertNotNull($vatica1);
        $this->assertTrue($vatica1->needs_review);
        $this->assertSame('rules', $vatica1->source);
        $this->assertSame(84, $vatica1->confidence);

        $vatica2 = CategoryMedicineLink::where('category_id', $this->categoryId('herbal'))
            ->where('moh_product_id', 19837)
            ->first();
        $this->assertNotNull($vatica2);
        $this->assertTrue($vatica2->needs_review);
        $this->assertSame('product_class', $vatica2->source);
        $this->assertSame(99, $vatica2->confidence);

        $perfume = $this->linkFor(4412, 'skin-care-beauty');
        $this->assertNotNull($perfume);
        $this->assertTrue($perfume->needs_review);
    }

    public function test_multi_category_medicine_creates_multiple_links(): void
    {
        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        // BABY MULTIVIT (row 3) — two categories: mother-and-child + vitamins-and-dietary-supplements
        $baby = $this->linkFor(1003, 'mother-baby');
        $vitamin = $this->linkFor(1003, 'vitamins-supplements');

        $this->assertNotNull($baby);
        $this->assertNotNull($vitamin);
        $this->assertSame(70, $baby->confidence);
        $this->assertSame(75, $vitamin->confidence);
        $this->assertSame(2, CategoryMedicineLink::where('moh_product_id', 1003)->count());
    }

    public function test_transaction_rolls_back_on_error(): void
    {
        // نختبر rollback فعلي: JSON فيه صفّان كلاهما سيُنشئ link.
        // نُسجّل model event على CategoryMedicineLink يرمي استثناء عند إنشاء
        // الـlink الثاني (moh_product_id=5002). الـcommand يلفّ الـsync بـ
        // DB::transaction() فيجب أن يتراجع كل شيء — حتى الـlink الأول.

        $testJson = base_path('storage/app/testing/fixtures/categorized_rollback.json');
        file_put_contents($testJson, json_encode([
            [
                'id' => 1,
                'trade_name' => 'FIRST MEDICINE',
                'moh_product_id' => 5001,
                'moh_drug_id' => null,
                'categories' => [
                    ['id' => 1, 'name_ar' => 'الأدوية', 'slug' => 'medicines', 'confidence' => 90, 'source' => 'product_class', 'needs_review' => false],
                ],
            ],
            [
                'id' => 2,
                'trade_name' => 'SECOND MEDICINE',
                'moh_product_id' => 5002,
                'moh_drug_id' => null,
                'categories' => [
                    ['id' => 1, 'name_ar' => 'الأدوية', 'slug' => 'medicines', 'confidence' => 85, 'source' => 'rules', 'needs_review' => false],
                ],
            ],
        ]));

        // سجّل event يرمي عند محاولة إنشاء link لـ moh_product_id=5002
        $triggerProductId = 5002;
        CategoryMedicineLink::creating(function (CategoryMedicineLink $link) use ($triggerProductId) {
            if ((int) ($link->moh_product_id ?? 0) === $triggerProductId) {
                throw new \RuntimeException('Simulated DB error on second row');
            }
        });

        // الـsync يبدأ transaction، يُنشئ link1 بنجاح، ثم يحاول إنشاء link2
        // فيرمي الـevent استثناء → transaction rollback → لا links إطلاقاً
        $threw = false;
        try {
            $this->artisan('moh:sync-categories', [
                '--source' => $testJson,
            ]);
        } catch (\RuntimeException $e) {
            $threw = true;
        } finally {
            @unlink($testJson);
        }

        // Transaction rollback: لا يوجد أي link — حتى link1 الذي أُنشئ قبل الفشل
        $this->assertTrue($threw, 'sync must throw when the event fires');
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 5001)->count(),
            'link1 must not exist — transaction rolled back');
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 5002)->count(),
            'link2 must not exist — transaction rolled back');
    }

    public function test_sync_bumps_cache_version(): void
    {
        $before = CategoryCatalogCache::version();

        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ])->assertSuccessful();

        $after = CategoryCatalogCache::version();
        $this->assertGreaterThan($before, $after, 'cache version must bump after sync');
    }

    public function test_print_plan_shows_counts_without_writing(): void
    {
        $before = CategoryMedicineLink::count();
        $this->assertSame(0, $before);

        $this->artisan('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
            '--print-plan' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_works_with_real_full_categorized_json_in_sqlite(): void
    {
        // هذه الاختبار الكبير — يستخدم الملف الكامل. يتأكد إن الـcommand ينجح
        // مع كل الـ18,330 رابط على sqlite in-memory بنفس المنطق.
        $fullPath = base_path('database/data/moh_medicines_categorized.json');

        $this->artisan('moh:sync-categories', [
            '--source' => $fullPath,
        ])->assertSuccessful();

        // نتوقع 18,330 رابط و 3 needs_review
        $this->assertSame(18330, CategoryMedicineLink::count());
        $this->assertSame(3, CategoryMedicineLink::where('needs_review', true)->count());
    }

    public function test_alias_map_covers_all_differing_json_slugs(): void
    {
        // الـalias map يجب أن يحتوي على كل الـslugs المختلفة بين JSON والـSeeder
        $expectedAliases = [
            'mother-and-child' => 'mother-baby',
            'personal-care-and-beauty' => 'skin-care-beauty',
            'health-and-wellness' => 'health-safety',
            'vitamins-and-dietary-supplements' => 'vitamins-supplements',
            'herbal-products' => 'herbal',
        ];

        $reflection = new \ReflectionClass(MohCategorySync::class);
        $const = $reflection->getConstant('SLUG_ALIAS_MAP');

        $this->assertSame($expectedAliases, $const);
    }
}