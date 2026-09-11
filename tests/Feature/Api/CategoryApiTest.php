<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\MohMedicine;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    private function categoryId(string $slug): int
    {
        return (int) Category::where('slug', $slug)->value('id');
    }

    private function createMohMedicine(array $attributes = []): MohMedicine
    {
        return MohMedicine::create($attributes + [
            'trade_name' => 'CATMED 5mg',
            'moh_product_id' => 2001,
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

        $this->createMohMedicine(['trade_name' => 'ALPHA MED 5mg', 'moh_product_id' => 2001]);
        $this->createMohMedicine(['trade_name' => 'BETA MED 5mg', 'moh_product_id' => 2002]);
        $this->createMohMedicine(['trade_name' => 'GAMMA OTHER 5mg', 'moh_product_id' => 2003]);

        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 2002, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $response = $this->getJson('/api/categories/medicines/medicines');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'trade_name', 'generic_name', 'dosage_form', 'product_class']],
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

        $this->createMohMedicine(['trade_name' => 'ALPHA MED 5mg', 'moh_product_id' => 2001]);
        $this->createMohMedicine(['trade_name' => 'BETA MED 5mg', 'moh_product_id' => 2002]);
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

        $this->createMohMedicine(['trade_name' => 'PANARELief 500mg', 'moh_product_id' => 2001]);
        $this->createMohMedicine(['trade_name' => 'IBULief 400mg', 'moh_product_id' => 2002]);
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

    public function test_medicines_index_rejects_unknown_category_id(): void
    {
        $this->getJson('/api/medicines?category_id=424242')->assertStatus(422);
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

        $this->createMohMedicine(['trade_name' => 'CACHED CAT MED', 'moh_product_id' => 7001]);
        CategoryMedicineLink::create(['category_id' => $category, 'moh_product_id' => 7001, 'source' => 'admin', 'confidence' => 100, 'needs_review' => false]);

        $first = $this->getJson('/api/categories/medicines/medicines');
        $second = $this->getJson('/api/categories/medicines/medicines');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('pagination.total'), $second->json('pagination.total'));
        $this->assertSame(1, $second->json('pagination.total'));
    }

    public function test_cache_store_is_array_in_testing(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertInstanceOf(\Illuminate\Cache\ArrayStore::class, Cache::store()->getStore());
    }
}
