<?php

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassifyMohCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    private function createMohRow(array $attributes = []): MohMedicine
    {
        return MohMedicine::create($attributes + [
            'trade_name' => 'CATALOG ROW',
            'dosage_form' => 'Tablet',
        ]);
    }

    private function categoryId(string $slug): int
    {
        return (int) Category::where('slug', $slug)->value('id');
    }

    private function linkFor(?int $productId, ?string $slug = null, ?int $drugId = null): ?CategoryMedicineLink
    {
        return CategoryMedicineLink::query()
            ->when($slug !== null, fn ($query) => $query->where('category_id', $this->categoryId($slug)))
            ->when($productId !== null, fn ($query) => $query->where('moh_product_id', $productId))
            ->when($drugId !== null, fn ($query) => $query->where('moh_drug_id', $drugId))
            ->first();
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

    public function test_product_class_rows_map_to_expected_categories(): void
    {
        $this->createMohRow(['trade_name' => 'SKINGLOW LOTION A', 'product_class' => 'Cosmetic Products', 'moh_product_id' => 1]);
        $this->createMohRow(['trade_name' => 'DIAGNOSE KIT B', 'product_class' => 'Medical Devices', 'moh_product_id' => 2]);
        $this->createMohRow(['trade_name' => 'FOODPLUS C', 'product_class' => 'Food Supplement', 'moh_product_id' => 3]);
        $this->createMohRow(['trade_name' => 'VETMED D1', 'product_class' => 'Veterinary Products', 'moh_product_id' => 4]);
        $this->createMohRow(['trade_name' => 'HERBAMIX E', 'product_class' => 'Herbal Products', 'moh_product_id' => 5]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $expected = [
            1 => ['skin-care-beauty', 'product_class', 95],
            2 => ['medical-supplies', 'product_class', 95],
            3 => ['vitamins-supplements', 'product_class', 95],
            4 => ['veterinary', 'product_class', 95],
            5 => ['herbal', 'product_class', 95],
        ];

        foreach ($expected as $productId => [$slug, $source, $confidence]) {
            $link = $this->linkFor($productId, $slug);
            $this->assertNotNull($link, "Missing link for moh_product_id {$productId} in {$slug}");
            $this->assertSame($source, $link->source);
            $this->assertSame($confidence, $link->confidence);
            $this->assertFalse($link->needs_review);
        }

        $this->assertSame(5, CategoryMedicineLink::count());
    }

    public function test_recalls_class_gets_no_link(): void
    {
        $this->createMohRow(['trade_name' => 'RECALL ITEM X', 'product_class' => 'Recalls & Alerts', 'moh_product_id' => 70]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_price_list_rule_links_to_medicines_with_both_stable_keys(): void
    {
        $this->createMohRow([
            'trade_name' => 'TESTMED 500MG',
            'generic_name' => 'PARACETAMOL',
            'official_price' => 10.5,
            'moh_product_id' => 100,
            'moh_drug_id' => 10,
            'product_class' => null,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $link = $this->linkFor(100, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame(10, (int) $link->moh_drug_id);
        $this->assertSame('rules', $link->source);
        $this->assertSame(80, $link->confidence);
        $this->assertFalse($link->needs_review);
        $this->assertSame(1, CategoryMedicineLink::count());
    }

    public function test_human_drug_with_price_gets_single_medicines_link_at_highest_confidence(): void
    {
        $this->createMohRow([
            'trade_name' => 'AMOXIDRUG TAB',
            'generic_name' => 'AMOXICILLIN',
            'official_price' => 20,
            'moh_product_id' => 101,
            'moh_drug_id' => 11,
            'product_class' => 'Human Drug Products',
            'dosage_form' => 'Film coated tablet',
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $link = $this->linkFor(101, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame(11, (int) $link->moh_drug_id);
        $this->assertSame('product_class', $link->source);
        $this->assertSame(90, $link->confidence);
        $this->assertSame(1, CategoryMedicineLink::count());
    }

    public function test_keyword_rule_links_eye_drops_to_eye_care(): void
    {
        $this->createMohRow([
            'trade_name' => 'XYZ EYE DROPS',
            'product_class' => null,
            'moh_product_id' => 30,
            'generic_name' => null,
            'dosage_form' => null,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $link = $this->linkFor(30, 'eye-care');
        $this->assertNotNull($link);
        $this->assertSame('rules', $link->source);
        $this->assertSame(70, $link->confidence);
        $this->assertFalse($link->needs_review);
    }

    public function test_row_without_any_stable_key_is_not_linked(): void
    {
        $this->createMohRow(['trade_name' => 'QQQ ZZZ 88', 'product_class' => null, 'moh_product_id' => null, 'moh_drug_id' => null]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::count());
        $this->assertSame(1, MohMedicine::count());
    }

    public function test_gibberish_row_with_key_is_counted_unclassified_without_links(): void
    {
        $this->createMohRow(['trade_name' => 'QQQ ZZZ 88', 'product_class' => null, 'moh_product_id' => 40]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertNull($this->linkFor(40));
        $this->assertSame(0, CategoryMedicineLink::count());
    }

    public function test_single_row_can_be_linked_to_multiple_categories(): void
    {
        $this->createMohRow([
            'trade_name' => 'BABY VITAMIN SYRUP',
            'product_class' => null,
            'moh_product_id' => 60,
            'generic_name' => null,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $babyLink = $this->linkFor(60, 'mother-baby');
        $vitaminLink = $this->linkFor(60, 'vitamins-supplements');

        $this->assertNotNull($babyLink);
        $this->assertNotNull($vitaminLink);
        $this->assertSame('rules', $babyLink->source);
        $this->assertSame('rules', $vitaminLink->source);
        $this->assertSame(70, $babyLink->confidence);
        $this->assertSame(70, $vitaminLink->confidence);
        $this->assertSame(2, CategoryMedicineLink::count());
    }

    public function test_command_is_idempotent_on_second_run(): void
    {
        $this->createMohRow(['trade_name' => 'SKINGLOW LOTION A', 'product_class' => 'Cosmetic Products', 'moh_product_id' => 1]);
        $this->createMohRow([
            'trade_name' => 'TESTMED 500MG',
            'generic_name' => 'PARACETAMOL',
            'official_price' => 10.5,
            'moh_product_id' => 100,
            'moh_drug_id' => 10,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();
        $countAfterFirstRun = CategoryMedicineLink::count();
        $this->assertSame(2, $countAfterFirstRun);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame($countAfterFirstRun, CategoryMedicineLink::count());

        $link = $this->linkFor(100, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame('rules', $link->source);
        $this->assertSame(80, $link->confidence);
    }

    public function test_fresh_rebuilds_auto_links_but_preserves_admin_links(): void
    {
        $medicinesId = $this->categoryId('medicines');
        $eyeCareId = $this->categoryId('eye-care');

        $adminLink = CategoryMedicineLink::create([
            'category_id' => $medicinesId,
            'moh_product_id' => 777,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $staleAutoLink = CategoryMedicineLink::create([
            'category_id' => $eyeCareId,
            'moh_product_id' => 888,
            'source' => 'rules',
            'confidence' => 70,
            'needs_review' => false,
        ]);

        $this->createMohRow(['trade_name' => 'SKINGLOW LOTION A', 'product_class' => 'Cosmetic Products', 'moh_product_id' => 80]);

        $this->artisan('classify:moh-catalog', ['--fresh' => true])->assertSuccessful();

        $this->assertDatabaseHas('category_medicine_links', ['id' => $adminLink->id, 'moh_product_id' => 777, 'source' => 'admin', 'confidence' => 100]);
        $this->assertDatabaseMissing('category_medicine_links', ['id' => $staleAutoLink->id]);

        $rebuilt = $this->linkFor(80, 'skin-care-beauty');
        $this->assertNotNull($rebuilt);
        $this->assertSame('product_class', $rebuilt->source);
        $this->assertSame(95, $rebuilt->confidence);

        $this->assertSame(2, CategoryMedicineLink::count());
    }

    public function test_links_survive_moh_catalog_reimport(): void
    {
        $this->createMohRow([
            'trade_name' => 'REIMPORT MED 1',
            'generic_name' => 'PARACETAMOL',
            'official_price' => 9.9,
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
            'product_class' => null,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $link = $this->linkFor(1001, 'medicines');
        $this->assertNotNull($link);
        $this->assertSame(5001, (int) $link->moh_drug_id);
        $this->assertSame(1, CategoryMedicineLink::count());

        $oldMohId = (int) MohMedicine::where('moh_product_id', 1001)->value('id');

        MohMedicine::where('id', '>', 0)->delete();
        $this->assertSame(0, MohMedicine::count());

        MohMedicine::create([
            'trade_name' => 'REIMPORT MED 1',
            'generic_name' => 'PARACETAMOL',
            'official_price' => 9.9,
            'moh_product_id' => 1001,
            'moh_drug_id' => 5001,
            'product_class' => null,
        ]);

        $newMohId = (int) MohMedicine::where('moh_product_id', 1001)->value('id');
        $this->assertNotSame($oldMohId, $newMohId, 'Re-imported row must get a different auto-increment id');

        $this->assertSame(1, CategoryMedicineLink::count(), 'Links must not be affected by re-import');
        $this->assertTrue(MohMedicine::query()
            ->where('moh_product_id', 1001)
            ->where('moh_drug_id', 5001)
            ->exists(), 'Re-imported row must resolve via stable keys');

        $moh = MohMedicine::where('moh_product_id', 1001)->first();
        $this->stockMedicine($moh);

        $response = $this->getJson("/api/categories/{$this->categoryId('medicines')}/medicines");
        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame('REIMPORT MED 1', $response->json('data.0.trade_name'));

        $this->artisan('classify:moh-catalog')->assertSuccessful();
        $this->assertSame(1, CategoryMedicineLink::count(), 'Re-classification after re-import must not duplicate links');
    }
}
