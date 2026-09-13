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

    private const FIXTURE = 'tests/fixtures/categorized_small.json';

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
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        // row 1: AMOXIDRUG â†’ medicines (product_class, 90, no review)
        $link = $this->linkFor(1001, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame('product_class', $link->source);
        $this->assertSame(90, $link->confidence);
        $this->assertFalse($link->needs_review);
        $this->assertSame(11, (int) $link->moh_drug_id);

        // row 2: SKINGLOW â†’ personal-care-and-beauty maps to skin-care-beauty (alias)
        $link = $this->linkFor(1002, 'skin-care-beauty');
        $this->assertNotNull($link, 'alias map should map personal-care-and-beauty â†’ skin-care-beauty');
        $this->assertSame('rules', $link->source);
        $this->assertSame(84, $link->confidence);
    }

    public function test_sync_is_idempotent_on_second_run(): void
    {
        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        $countFirst = CategoryMedicineLink::count();
        $this->assertSame(7, $countFirst, '6 rows: row1=1, row2=1, row3=2, row5=2, row6=1 = 7 links');

        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        $this->assertSame($countFirst, CategoryMedicineLink::count(), 'idempotent â€” no duplicates');
    }

    public function test_fresh_deletes_non_admin_links_but_preserves_admin(): void
    {
        $medicinesId = $this->categoryId('medicines');

        // ط£ظˆظ„ sync ط¨ط¯ظˆظ† admin link
        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();
        $countAfterFirst = CategoryMedicineLink::count();

        // ط§ظ„ط¢ظ† ظ†ط¶ظٹظپ admin link â€” ظ‡ط°ط§ ظ„ط§ ظٹظˆط¬ط¯ ظپظٹ ط§ظ„ظ€JSON ظˆظٹط¬ط¨ ط£ظ† ظٹط¨ظ‚ظ‰ ط¨ط¹ط¯ --fresh
        $adminLink = CategoryMedicineLink::create([
            'category_id' => $medicinesId,
            'moh_product_id' => 9999,
            'moh_drug_id' => 9999,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        // re-sync ط¨ظ€fresh: ظٹط­ط°ظپ 7 non-adminطŒ ظٹظڈظ†ط´ط¦ 7طŒ ظˆظٹط¨ظ‚ظ‰ admin
        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
            '--fresh' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('category_medicine_links', [
            'id' => $adminLink->id,
            'source' => 'admin',
            'moh_product_id' => 9999,
        ]);

        // admin link ظˆط§ط­ط¯ + 7 ظ…ظ† ط§ظ„ظ€JSON = 8 = $countAfterFirst + 1
        $this->assertSame($countAfterFirst + 1, CategoryMedicineLink::count());
    }

    public function test_admin_link_not_overwritten_even_when_json_has_higher_confidence(): void
    {
        $medicinesId = $this->categoryId('medicines');

        CategoryMedicineLink::create([
            'category_id' => $medicinesId,
            'moh_product_id' => 1001,  // AMOXIDRUG â€” ظ…ظˆط¬ظˆط¯ ظپظٹ ط§ظ„ظ€JSON ط¨ط«ظ‚ط© 90
            'moh_drug_id' => 11,
            'source' => 'admin',
            'confidence' => 50,  // ط£ظ‚ظ„ ظ…ظ† 90
            'needs_review' => false,
        ]);

        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        $link = $this->linkFor(1001, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame('admin', $link->source, 'admin source must be preserved');
        $this->assertSame(50, $link->confidence, 'admin confidence must not be overwritten');
    }

    public function test_unknown_json_slug_is_skipped_without_creating_category(): void
    {
        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        // row 4 (RECALL ITEM X) ط¹ظ†ط¯ظ‡ط§ slug="unknown-future-category" â€” ظ„ط§ط²ظ… طھظڈطھط¬ط§ظ‡ظژظ„
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 1004)->count());
        $this->assertSame(0, Category::where('slug', 'unknown-future-category')->count());
    }

    public function test_dry_run_does_not_write_to_db(): void
    {
        $before = CategoryMedicineLink::count();
        $this->assertSame(0, $before);

        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count(), 'dry-run must not create any links');
    }

    public function test_needs_review_links_are_preserved_with_correct_flag(): void
    {
        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
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
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        // BABY MULTIVIT (row 3) â€” two categories: mother-and-child + vitamins-and-dietary-supplements
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
        // ظ†ط®طھط¨ط± rollback ظپط¹ظ„ظٹ: JSON ظپظٹظ‡ طµظپظ‘ط§ظ† ظƒظ„ط§ظ‡ظ…ط§ ط³ظٹظڈظ†ط´ط¦ link.
        // ظ†ظڈط³ط¬ظ‘ظ„ model event ط¹ظ„ظ‰ CategoryMedicineLink ظٹط±ظ…ظٹ ط§ط³طھط«ظ†ط§ط، ط¹ظ†ط¯ ط¥ظ†ط´ط§ط،
        // ط§ظ„ظ€link ط§ظ„ط«ط§ظ†ظٹ (moh_product_id=5002). ط§ظ„ظ€command ظٹظ„ظپظ‘ ط§ظ„ظ€sync ط¨ظ€
        // DB::transaction() ظپظٹط¬ط¨ ط£ظ† ظٹطھط±ط§ط¬ط¹ ظƒظ„ ط´ظٹط، â€” ط­طھظ‰ ط§ظ„ظ€link ط§ظ„ط£ظˆظ„.

        // نكتب داخل tests/fixtures (مرفوع بالـ git) — storage/app مستثنى من المستودع
        // فالمجلد غير موجود على CI. ننشئ الملف مؤقتاً ونحذفه في finally.
        $fixtureDir = base_path('tests/fixtures');
        if (! is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0777, true);
        }
        $testJson = $fixtureDir.'/categorized_rollback.json';
        file_put_contents($testJson, json_encode([
            [
                'id' => 1,
                'trade_name' => 'FIRST MEDICINE',
                'moh_product_id' => 5001,
                'moh_drug_id' => null,
                'categories' => [
                    ['id' => 1, 'name_ar' => 'ط§ظ„ط£ط¯ظˆظٹط©', 'slug' => 'medicines', 'confidence' => 90, 'source' => 'product_class', 'needs_review' => false],
                ],
            ],
            [
                'id' => 2,
                'trade_name' => 'SECOND MEDICINE',
                'moh_product_id' => 5002,
                'moh_drug_id' => null,
                'categories' => [
                    ['id' => 1, 'name_ar' => 'ط§ظ„ط£ط¯ظˆظٹط©', 'slug' => 'medicines', 'confidence' => 85, 'source' => 'rules', 'needs_review' => false],
                ],
            ],
        ]));

        // ط³ط¬ظ‘ظ„ event ظٹط±ظ…ظٹ ط¹ظ†ط¯ ظ…ط­ط§ظˆظ„ط© ط¥ظ†ط´ط§ط، link ظ„ظ€ moh_product_id=5002
        $triggerProductId = 5002;
        CategoryMedicineLink::creating(function (CategoryMedicineLink $link) use ($triggerProductId) {
            if ((int) ($link->moh_product_id ?? 0) === $triggerProductId) {
                throw new \RuntimeException('Simulated DB error on second row');
            }
        });

        // ط§ظ„ظ€sync ظٹط¨ط¯ط£ transactionطŒ ظٹظڈظ†ط´ط¦ link1 ط¨ظ†ط¬ط§ط­طŒ ط«ظ… ظٹط­ط§ظˆظ„ ط¥ظ†ط´ط§ط، link2
        // ظپظٹط±ظ…ظٹ ط§ظ„ظ€event ط§ط³طھط«ظ†ط§ط، â†’ transaction rollback â†’ ظ„ط§ links ط¥ط·ظ„ط§ظ‚ط§ظ‹
        $threw = false;
        try {
            $this->artisan('moh:sync-categories', [
                '--file' => 'tests/fixtures/categorized_rollback.json',
            ]);
        } catch (\RuntimeException $e) {
            $threw = true;
        } finally {
            @unlink($testJson);
        }

        // Transaction rollback: ظ„ط§ ظٹظˆط¬ط¯ ط£ظٹ link â€” ط­طھظ‰ link1 ط§ظ„ط°ظٹ ط£ظڈظ†ط´ط¦ ظ‚ط¨ظ„ ط§ظ„ظپط´ظ„
        $this->assertTrue($threw, 'sync must throw when the event fires');
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 5001)->count(),
            'link1 must not exist â€” transaction rolled back');
        $this->assertSame(0, CategoryMedicineLink::where('moh_product_id', 5002)->count(),
            'link2 must not exist â€” transaction rolled back');
    }

    public function test_sync_bumps_cache_version(): void
    {
        $before = CategoryCatalogCache::version();

        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ])->assertSuccessful();

        $after = CategoryCatalogCache::version();
        $this->assertGreaterThan($before, $after, 'cache version must bump after sync');
    }

    public function test_print_plan_shows_counts_without_writing(): void
    {
        $before = CategoryMedicineLink::count();
        $this->assertSame(0, $before);

        $this->artisan('moh:sync-categories', [
            '--file' => self::FIXTURE,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_works_with_real_full_categorized_json_in_sqlite(): void
    {
        // ظ‡ط°ظ‡ ط§ظ„ط§ط®طھط¨ط§ط± ط§ظ„ظƒط¨ظٹط± â€” ظٹط³طھط®ط¯ظ… ط§ظ„ظ…ظ„ظپ ط§ظ„ظƒط§ظ…ظ„. ظٹطھط£ظƒط¯ ط¥ظ† ط§ظ„ظ€command ظٹظ†ط¬ط­
        // ظ…ط¹ ظƒظ„ ط§ظ„ظ€18,330 ط±ط§ط¨ط· ط¹ظ„ظ‰ sqlite in-memory ط¨ظ†ظپط³ ط§ظ„ظ…ظ†ط·ظ‚.
        // مسار نسبي — الكوماند يطبّق base_path() داخلياً على قيمة --file
        $fullPath = 'database/data/moh_medicines_categorized.json';

        $this->artisan('moh:sync-categories', [
            '--file' => $fullPath,
        ])->assertSuccessful();

        // ظ†طھظˆظ‚ط¹ 18,330 ط±ط§ط¨ط· ظˆ 3 needs_review
        $this->assertSame(18330, CategoryMedicineLink::count());
        $this->assertSame(3, CategoryMedicineLink::where('needs_review', true)->count());
    }

    public function test_alias_map_covers_all_differing_json_slugs(): void
    {
        // ط§ظ„ظ€alias map ظٹط¬ط¨ ط£ظ† ظٹط­طھظˆظٹ ط¹ظ„ظ‰ ظƒظ„ ط§ظ„ظ€slugs ط§ظ„ظ…ط®طھظ„ظپط© ط¨ظٹظ† JSON ظˆط§ظ„ظ€Seeder
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