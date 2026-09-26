<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Support\CategoryCatalogCache;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CategorySyncApiTest extends TestCase
{
    private const FIXTURE = 'tests/fixtures/categorized_small.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

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

    private function stockAllFixtureMedicines(): void
    {
        $user = User::factory()->create();
        $pharmacy = Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'SYNC-'.uniqid(),
            'pharmacy_name' => 'Sync Stock Pharmacy '.uniqid(),
            'address' => 'Addr',
            'phone_number' => '0590000000',
            'region' => 'Region',
            'is_active' => true,
            'avg_rating' => 0,
        ]));

        $rows = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        foreach ($rows as $row) {
            $moh = MohMedicine::where('moh_product_id', $row['moh_product_id'])->first();
            if ($moh) {
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
        }
    }

    public function test_api_index_shows_zero_counts_without_inventory(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ]);

        $after = $this->getJson('/api/categories')->json('data');
        $bySlug = collect($after)->keyBy('slug');

        $this->assertSame(0, $bySlug['medicines']['medicines_count']);
        $this->assertSame(0, $bySlug['skin-care-beauty']['medicines_count']);
        $this->assertSame(0, $bySlug['mother-baby']['medicines_count']);
        $this->assertSame(0, $bySlug['vitamins-supplements']['medicines_count']);
        $this->assertSame(0, $bySlug['herbal']['medicines_count']);
        $this->assertSame(0, $bySlug['dental-care']['medicines_count']);
    }

    public function test_category_medicine_endpoint_returns_empty_without_inventory(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ]);

        $skinCare = Category::where('slug', 'skin-care-beauty')->firstOrFail();
        $response = $this->getJson("/api/categories/{$skinCare->id}/medicines");

        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);

        $this->assertSame(0, $response->json('pagination.total'));
    }

    public function test_category_medicines_endpoint_returns_data_with_inventory(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ]);

        $this->stockAllFixtureMedicines();

        $skinCare = Category::where('slug', 'skin-care-beauty')->firstOrFail();
        $response = $this->getJson("/api/categories/{$skinCare->id}/medicines");

        $response->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'medicine_id', 'trade_name', 'source', 'confidence', 'needs_review']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);

        $this->assertSame(3, $response->json('pagination.total'));
        $names = collect($response->json('data'))->pluck('trade_name')->all();
        $this->assertContains('SKINGLOW LOTION A', $names);
        $this->assertContains('VATIKA COLOR PROTECT', $names);
        $this->assertContains('PERFUME SPRAY FOR MEN', $names);
    }

    public function test_cache_version_bumps_after_sync(): void
    {
        $this->getJson('/api/categories')->assertOk();
        $versionBefore = CategoryCatalogCache::version();

        $this->seedMohMedicinesFromFixture();
        Artisan::call('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ]);

        $versionAfter = CategoryCatalogCache::version();
        $this->assertGreaterThan($versionBefore, $versionAfter);

        $fresh = $this->getJson('/api/categories')->json('data');
        $bySlug = collect($fresh)->keyBy('slug');
        $this->assertSame(0, $bySlug['medicines']['medicines_count']);
    }

    public function test_admin_link_persists_across_sync(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--file' => self::FIXTURE,
        ]);

        $medicines = Category::where('slug', 'medicines')->firstOrFail();
        CategoryMedicineLink::create([
            'category_id' => $medicines->id,
            'moh_product_id' => 8888,
            'moh_drug_id' => 9999,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $medicines->id,
            'moh_product_id' => 8888,
            'source' => 'admin',
        ]);

        $bySlug = collect($this->getJson('/api/categories')->json('data'))->keyBy('slug');
        $this->assertSame(0, $bySlug['medicines']['medicines_count']);
    }

    public function test_full_pipeline_counts_match_available_only(): void
    {
        $this->seedMohMedicinesFromFixture();

        Artisan::call('moh:sync-categories', [
            '--file' => 'database/data/moh_medicines_categorized.json',
        ]);

        $this->assertSame(18330, CategoryMedicineLink::count());
        $this->assertSame(3, CategoryMedicineLink::where('needs_review', true)->count());

        $bySlug = collect($this->getJson('/api/categories')->json('data'))->keyBy('slug');

        $this->assertSame(0, $bySlug['medicines']['medicines_count']);
        $this->assertSame(0, $bySlug['skin-care-beauty']['medicines_count']);
        $this->assertSame(0, $bySlug['vitamins-supplements']['medicines_count']);
        $this->assertSame(0, $bySlug['medical-supplies']['medicines_count']);
    }
}