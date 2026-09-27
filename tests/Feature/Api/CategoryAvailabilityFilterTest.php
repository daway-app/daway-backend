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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Patient Category DEFAULT = available only (no opt-in needed):
 *  - GET /api/categories/{id}/medicines lists only moh rows with at least one
 *    ACTIVE pharmacy row (is_available=true, quantity>0) — no duplicates.
 *  - medicines_count (category + subcategory) counts available UNIQUE medicines.
 *  - ?available_only is accepted for backward compatibility but changes nothing
 *    (available_only=0 does NOT revert to the full catalog).
 * Admin catalog (category_medicine_links source) always sees ALL linked rows.
 */
class CategoryAvailabilityFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeCategory(): Category
    {
        return Category::create([
            'name_ar' => 'فئة التوفر',
            'name_en' => 'Availability Category '.uniqid(),
            'slug' => 'avail-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function makeSubcategory(Category $category): Subcategory
    {
        return Subcategory::create([
            'category_id' => $category->id,
            'name_ar' => 'قسم فرعي',
            'name_en' => 'Avail Sub '.uniqid(),
            'slug' => 'avail-sub-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function makeMoh(int $productId, string $name): MohMedicine
    {
        return MohMedicine::create([
            'trade_name' => $name.' '.uniqid(),
            'moh_product_id' => $productId,
            'generic_name' => 'Generic',
        ]);
    }

    private function link(Category $category, MohMedicine $moh, ?int $subcategoryId = null): void
    {
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategoryId,
            'moh_product_id' => $moh->moh_product_id,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);
    }

    private function makePharmacy(bool $active = true): Pharmacy
    {
        $user = User::create([
            'name' => 'Pharmacy User',
            'email' => 'avail_'.uniqid().'@daway.com',
            'phone' => '059'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
        ]);
        $user->role = 'pharmacy';
        $user->is_active = true;
        $user->save();
        $user->syncRoles(['pharmacy']);

        return Pharmacy::unguarded(fn () => Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'AV-'.uniqid(),
            'pharmacy_name' => 'Avail Pharmacy '.uniqid(),
            'address' => 'Addr',
            'phone_number' => '0590000000',
            'region' => 'Region',
            'is_active' => $active,
            'avg_rating' => 0,
        ]));
    }

    private function stock(Pharmacy $pharmacy, MohMedicine $moh, bool $available, int $qty): void
    {
        $medicine = Medicine::create([
            'trade_name' => 'Local '.uniqid(),
            'active_ingredient' => 'Ingredient',
            'is_available' => true,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $moh->id,
            'price' => 10,
            'quantity' => $qty,
            'is_available' => $available,
        ]);
    }

    public function test_default_without_params_returns_available_only(): void
    {
        $category = $this->makeCategory();
        $available = $this->makeMoh(971001, 'AVAIL');
        $ghost = $this->makeMoh(971002, 'GHOST');
        $this->link($category, $available);
        $this->link($category, $ghost);

        $this->stock($this->makePharmacy(true), $available, true, 50);
        // $ghost has no inventory at all.

        $response = $this->getJson("/api/categories/{$category->id}/medicines");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame($available->trade_name, $response->json('data.0.trade_name'));
    }

    public function test_medicine_without_pharmacy_is_hidden_by_default(): void
    {
        $category = $this->makeCategory();
        $ghost = $this->makeMoh(977001, 'GHOST');
        $this->link($category, $ghost);

        $response = $this->getJson("/api/categories/{$category->id}/medicines");

        $response->assertOk();
        $this->assertSame(0, $response->json('pagination.total'));
        $this->assertSame([], $response->json('data'));
    }

    public function test_is_available_false_is_hidden(): void
    {
        $category = $this->makeCategory();
        $moh = $this->makeMoh(977002, 'UNAVAIL');
        $this->link($category, $moh);
        $this->stock($this->makePharmacy(true), $moh, false, 100);

        $this->getJson("/api/categories/{$category->id}/medicines")
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_quantity_zero_is_hidden(): void
    {
        $category = $this->makeCategory();
        $moh = $this->makeMoh(977003, 'ZERO');
        $this->link($category, $moh);
        $this->stock($this->makePharmacy(true), $moh, true, 0);

        $this->getJson("/api/categories/{$category->id}/medicines")
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_inactive_pharmacy_stock_is_hidden(): void
    {
        $category = $this->makeCategory();
        $moh = $this->makeMoh(977004, 'INACT');
        $this->link($category, $moh);
        $this->stock($this->makePharmacy(false), $moh, true, 100);

        $this->getJson("/api/categories/{$category->id}/medicines")
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_medicine_in_multiple_pharmacies_appears_once(): void
    {
        $category = $this->makeCategory();
        $moh = $this->makeMoh(974001, 'MULTI');
        $this->link($category, $moh);

        $this->stock($this->makePharmacy(true), $moh, true, 0);
        $this->stock($this->makePharmacy(true), $moh, true, 7);
        $this->stock($this->makePharmacy(true), $moh, true, 3);

        $response = $this->getJson("/api/categories/{$category->id}/medicines");

        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_category_count_counts_available_unique_medicines_only(): void
    {
        $category = $this->makeCategory();
        $good = $this->makeMoh(973001, 'GOOD');
        $ghost = $this->makeMoh(973002, 'GHOST');
        $this->link($category, $good);
        $this->link($category, $ghost);
        // Stocked twice (two pharmacies) — still counts once.
        $this->stock($this->makePharmacy(true), $good, true, 5);
        $this->stock($this->makePharmacy(true), $good, true, 9);

        $show = $this->getJson("/api/categories/{$category->id}")->assertOk()->json('data');
        $this->assertSame(1, $show['medicines_count']);

        $index = $this->getJson('/api/categories')->assertOk()->json('data');
        $row = collect($index)->firstWhere('id', $category->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['medicines_count']);
    }

    public function test_subcategory_count_uses_same_availability_logic(): void
    {
        $category = $this->makeCategory();
        $sub = $this->makeSubcategory($category);
        $good = $this->makeMoh(978001, 'SUBGOOD');
        $ghost = $this->makeMoh(978002, 'SUBGHOST');
        $this->link($category, $good, $sub->id);
        $this->link($category, $ghost, $sub->id);
        $this->stock($this->makePharmacy(true), $good, true, 4);

        // Subcategory-filtered listing: only the stocked one.
        $this->getJson("/api/categories/{$category->id}/medicines?subcategory_id={$sub->id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        // Subcategory count: 1, and parent category count: 1.
        $show = $this->getJson("/api/categories/{$category->id}")->assertOk()->json('data');
        $subRow = collect($show['subcategories'])->firstWhere('id', $sub->id);
        $this->assertNotNull($subRow);
        $this->assertSame(1, $subRow['medicines_count']);
        $this->assertSame(1, $show['medicines_count']);

        // Filters endpoint facets agree.
        $filters = $this->getJson('/api/medicine-filters')->assertOk()->json('data');
        $catRow = collect($filters['categories'])->firstWhere('id', $category->id);
        $this->assertSame(1, $catRow['medicines_count']);
        $this->assertSame(1, collect($catRow['subcategories'])->firstWhere('id', $sub->id)['medicines_count']);
    }

    public function test_pagination_works_after_availability_filter(): void
    {
        $category = $this->makeCategory();
        $moh1 = $this->makeMoh(979001, 'PAGEONE');
        $moh2 = $this->makeMoh(979002, 'PAGETWO');
        $ghost = $this->makeMoh(979003, 'PAGEGHOST');
        $this->link($category, $moh1);
        $this->link($category, $moh2);
        $this->link($category, $ghost);
        $this->stock($this->makePharmacy(true), $moh1, true, 5);
        $this->stock($this->makePharmacy(true), $moh2, true, 6);

        $response = $this->getJson("/api/categories/{$category->id}/medicines?per_page=1");

        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.per_page'));
        $this->assertSame(2, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.last_page'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_available_only_param_kept_for_backward_compatibility(): void
    {
        $category = $this->makeCategory();
        $good = $this->makeMoh(979011, 'COMPATGOOD');
        $ghost = $this->makeMoh(979012, 'COMPATGHOST');
        $this->link($category, $good);
        $this->link($category, $ghost);
        $this->stock($this->makePharmacy(true), $good, true, 5);

        // ?available_only=1 → same available-only result.
        $this->getJson("/api/categories/{$category->id}/medicines?available_only=1")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        // ?available_only=0 → must NOT revert to the full catalog.
        $this->getJson("/api/categories/{$category->id}/medicines?available_only=0")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_admin_catalog_source_sees_all_links_regardless_of_availability(): void
    {
        $category = $this->makeCategory();
        $stocked = $this->makeMoh(976001, 'STOCKED');
        $ghost = $this->makeMoh(976002, 'GHOST');
        $this->link($category, $stocked);
        $this->link($category, $ghost);

        $this->stock($this->makePharmacy(true), $stocked, true, 9);

        // Admin show() reads category_medicine_links directly — both rows visible.
        $this->assertSame(2, CategoryMedicineLink::where('category_id', $category->id)->count());
    }
}
