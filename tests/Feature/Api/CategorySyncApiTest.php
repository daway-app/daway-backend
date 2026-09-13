<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\MohMedicine;
use App\Support\CategoryCatalogCache;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CategorySyncApiTest extends TestCase
{
    private const FIXTURE = 'storage/app/testing/fixtures/categorized_small.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    /**
     * يبني سجلات moh_medicines مطابقة للـfixture كي يعمل الـjoin
     * في CategoryController::medicines() (الـendpoint يفحص whereExists على moh_medicines).
     */
    private function seedMohMedicinesFromFixture(): void
    {
        $rows = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        foreach ($rows as $row) {
            MohMedicine::create([
                'trade_name' => $row['trade_name'],
                'generic_name' => $row['generic_name'] ?? null,
                'moh_product_id' => $row['moh_product_id'],
                'moh_drug_id' => $row['moh_drug_id'] ?? null,
                'product_class' => $row['product_class'] ?? null,
                'dosage_form' => $row['dosage_form'] ?? null,
                'manufacturer' => $row['manufacturer'] ?? null,
                'company' => $row['company'] ?? null,
                'origin' => $row['origin'] ?? null,
            ]);
        }
    }

    public function test_api_index_exposes_real_medicines_count_after_sync(): void
    {
        // قبل sync: counts كلها 0
        $before = $this->getJson('/api/categories')->json('data');
        foreach ($before as $cat) {
            $this->assertSame(0, $cat['medicines_count'], "{$cat['slug']} should be 0 before sync");
        }

        // sync
        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ]);

        // بعد sync: counts حقيقية. حسب الـfixture:
        // medicines: 1 (row1)
        // skin-care-beauty: 2 (row2, row5, row6) — alias من personal-care-and-beauty
        // mother-baby: 1 (row3)
        // vitamins-supplements: 1 (row3)
        // herbal: 1 (row5)
        $after = $this->getJson('/api/categories')->json('data');
        $bySlug = collect($after)->keyBy('slug');

        $this->assertSame(1, $bySlug['medicines']['medicines_count']);
        $this->assertSame(3, $bySlug['skin-care-beauty']['medicines_count'], 'row2 + row5 + row6');
        $this->assertSame(1, $bySlug['mother-baby']['medicines_count']);
        $this->assertSame(1, $bySlug['vitamins-supplements']['medicines_count']);
        $this->assertSame(1, $bySlug['herbal']['medicines_count']);
        $this->assertSame(0, $bySlug['dental-care']['medicines_count'], 'no fixture links to dental');
    }

    public function test_api_category_medicines_endpoint_returns_paginated_with_classification_metadata(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ]);

        $skinCare = Category::where('slug', 'skin-care-beauty')->firstOrFail();
        $response = $this->getJson("/api/categories/{$skinCare->id}/medicines");

        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'medicine_id', 'trade_name', 'source', 'confidence', 'needs_review']],
            'pagination',
        ]);

        $this->assertSame(3, $response->json('pagination.total'));
        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains('SKINGLOW LOTION A', $names);
        $this->assertContains('VATIKA COLOR PROTECT', $names);
        $this->assertContains('PERFUME SPRAY FOR MEN', $names);

        // التحقق من بيانات التصنيف (classification metadata)
        $byName = collect($response->json('data'))->keyBy('trade_name');

        // SKINGLOW: source=rules, confidence=84, needs_review=false
        $skinglow = $byName['SKINGLOW LOTION A'];
        $this->assertSame('rules', $skinglow['source']);
        $this->assertSame(84, $skinglow['confidence']);
        $this->assertFalse($skinglow['needs_review']);

        // VATIKA: source=rules, confidence=84, needs_review=true
        $vatika = $byName['VATIKA COLOR PROTECT'];
        $this->assertSame('rules', $vatika['source']);
        $this->assertSame(84, $vatika['confidence']);
        $this->assertTrue($vatika['needs_review']);

        // PERFUME: source=rules, confidence=78, needs_review=true
        $perfume = $byName['PERFUME SPRAY FOR MEN'];
        $this->assertSame('rules', $perfume['source']);
        $this->assertSame(78, $perfume['confidence']);
        $this->assertTrue($perfume['needs_review']);
    }

    public function test_api_category_medicines_respects_pagination(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ]);

        $skinCare = Category::where('slug', 'skin-care-beauty')->firstOrFail();

        $response = $this->getJson("/api/categories/{$skinCare->id}/medicines?per_page=2");
        $response->assertOk();
        $this->assertSame(2, $response->json('pagination.per_page'));
        $this->assertSame(3, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.last_page'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_cache_version_bumps_after_sync_and_invalidates_warm_cache(): void
    {
        // warm cache
        $this->getJson('/api/categories')->assertOk();
        $versionBefore = CategoryCatalogCache::version();

        // sync
        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ]);

        $versionAfter = CategoryCatalogCache::version();
        $this->assertGreaterThan($versionBefore, $versionAfter);

        // الطلب الجديد يكشف البيانات الجديدة (counts بعد sync)
        $fresh = $this->getJson('/api/categories')->json('data');
        $bySlug = collect($fresh)->keyBy('slug');
        $this->assertSame(1, $bySlug['medicines']['medicines_count']);
    }

    public function test_sync_then_admin_link_attaches_correctly(): void
    {
        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
        ]);

        // admin link جديد بعد sync
        $medicines = Category::where('slug', 'medicines')->firstOrFail();
        CategoryMedicineLink::create([
            'category_id' => $medicines->id,
            'moh_product_id' => 8888,
            'moh_drug_id' => 9999,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        // re-sync بـfresh: admin link لازم يبقى
        Artisan::call('moh:sync-categories', [
            '--source' => base_path(self::FIXTURE),
            '--fresh' => true,
        ]);

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $medicines->id,
            'moh_product_id' => 8888,
            'source' => 'admin',
        ]);

        $bySlug = collect($this->getJson('/api/categories')->json('data'))->keyBy('slug');
        // medicines: 1 from JSON + 1 admin = 2
        $this->assertSame(2, $bySlug['medicines']['medicines_count']);
    }

    public function test_full_pipeline_with_full_categorized_json_consistency(): void
    {
        // استخدم الملف الكامل 17k صف للتأكد من أن الـsync + الـAPI
        // يعملان على حجم بيانات حقيقي بنفس النتيجة.
        Artisan::call('moh:sync-categories', [
            '--source' => base_path('database/data/moh_medicines_categorized.json'),
        ]);

        $this->assertSame(18330, CategoryMedicineLink::count());
        $this->assertSame(3, CategoryMedicineLink::where('needs_review', true)->count());

        $bySlug = collect($this->getJson('/api/categories')->json('data'))->keyBy('slug');

        // التحقق من 4 أقسام رئيسية
        $this->assertSame(5124, $bySlug['medicines']['medicines_count']);
        $this->assertSame(10462, $bySlug['skin-care-beauty']['medicines_count']);
        $this->assertSame(474, $bySlug['vitamins-supplements']['medicines_count']);
        $this->assertSame(954, $bySlug['medical-supplies']['medicines_count']);
    }
}