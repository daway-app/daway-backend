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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubcategoryFilterApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
        $this->seed(SubcategorySeeder::class);
    }

    private function mohMedicine(array $attrs): MohMedicine
    {
        return MohMedicine::create($attrs + ['origin' => 'Test']);
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

    public function test_categories_index_exposes_subcategories_with_group_key(): void
    {
        $cats = $this->getJson('/api/categories')->assertOk()->json('data');
        $medCat = collect($cats)->firstWhere('slug', 'medicines');
        $vitsCat = collect($cats)->firstWhere('slug', 'vitamins-supplements');

        $this->assertCount(6, $medCat['subcategories']);
        $this->assertSame('symptoms', $medCat['subcategories'][0]['group_key']);

        $this->assertCount(8, $vitsCat['subcategories'] ?? []);
        $groups = collect($vitsCat['subcategories'])->pluck('group_key')->unique()->all();
        $this->assertSame(['vitamins', 'supplements'], $groups);
    }

    public function test_medicines_endpoint_returns_empty_without_inventory(): void
    {
        $medicinesCat = Category::where('slug', 'medicines')->first();
        $allergySub = Subcategory::where('slug', 'allergy')->first();

        $allergy = $this->mohMedicine(['trade_name' => 'LORATADINE 10MG ALLERGY', 'moh_product_id' => 90001]);

        $this->stockMedicine($allergy);

        CategoryMedicineLink::create([
            'category_id' => $medicinesCat->id,
            'subcategory_id' => $allergySub->id,
            'moh_product_id' => $allergy->moh_product_id,
            'source' => 'rules',
            'confidence' => 90,
            'needs_review' => false,
        ]);

        $response = $this->getJson("/api/categories/{$medicinesCat->id}/medicines?subcategory=allergy");
        $response->assertOk();
        $this->assertGreaterThanOrEqual(0, $response->json('pagination.total'));
    }

    public function test_medicines_endpoint_filters_by_subcategory_slug_with_inventory(): void
    {
        $medicinesCat = Category::where('slug', 'medicines')->first();
        $allergySub = Subcategory::where('slug', 'allergy')->first();
        $coughSub = Subcategory::where('slug', 'cough-sore-throat')->first();

        $allergy = $this->mohMedicine(['trade_name' => 'LORATADINE 10MG ALLERGY', 'moh_product_id' => 90001, 'dosage_form' => 'Tablet']);
        $cough = $this->mohMedicine(['trade_name' => 'BRONCHOPANE COUGH SYRUP', 'moh_product_id' => 90002, 'dosage_form' => 'Syrup']);

        $this->stockMedicine($allergy);
        $this->stockMedicine($cough);

        foreach ([[$allergy, $allergySub], [$cough, $coughSub]] as [$moh, $sub]) {
            CategoryMedicineLink::create([
                'category_id' => $medicinesCat->id,
                'subcategory_id' => $sub->id,
                'moh_product_id' => $moh->moh_product_id,
                'source' => 'rules',
                'confidence' => 90,
                'needs_review' => false,
            ]);
        }

        $response = $this->getJson("/api/categories/{$medicinesCat->id}/medicines?subcategory=allergy");
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('LORATADINE 10MG ALLERGY', $response->json('data.0.trade_name'));

        $response = $this->getJson("/api/categories/{$medicinesCat->id}/medicines?subcategory=cough-sore-throat");
        $response->assertOk();
        $this->assertSame('BRONCHOPANE COUGH SYRUP', $response->json('data.0.trade_name'));
    }

    public function test_medicines_endpoint_filters_by_dosage_form_arabic(): void
    {
        $medicinesCat = Category::where('slug', 'medicines')->first();

        $syrup = $this->mohMedicine(['trade_name' => 'BRONCHOPANE SYRUP 200ML', 'moh_product_id' => 90003, 'dosage_form' => 'Syrup']);
        $tablet = $this->mohMedicine(['trade_name' => 'PANADOL TABLET 500', 'moh_product_id' => 90004, 'dosage_form' => 'Tablet']);

        $this->stockMedicine($syrup);

        CategoryMedicineLink::create([
            'category_id' => $medicinesCat->id,
            'moh_product_id' => $syrup->moh_product_id,
            'source' => 'rules',
            'confidence' => 90,
            'needs_review' => false,
        ]);

        $response = $this->getJson("/api/categories/{$medicinesCat->id}/medicines?dosage_form=syrup");
        $response->assertOk();
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame('BRONCHOPANE SYRUP 200ML', $response->json('data.0.trade_name'));
    }
}