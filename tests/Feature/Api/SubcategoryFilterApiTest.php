<?php
namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\MohMedicine;
use App\Models\Subcategory;
use Database\Seeders\CategorySeeder;
use Database\Seeders\SubcategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فلاتر القسم الفرعي — الفلترة داخل القسم عبر subcategory_id أو slug بصيغتها.
 * (دعم الطلب: داخل كل قسم فلاتر مثل فيتامينات الشعر/مكملات البروتين/حبوب-شراب)
 */
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

    public function test_medicines_endpoint_filters_by_subcategory_slug(): void
    {
        $medicinesCat = Category::where('slug', 'medicines')->first();
        $allergySub = Subcategory::where('slug', 'allergy')->first();
        $coughSub = Subcategory::where('slug', 'cough-sore-throat')->first();

        $allergy = $this->mohMedicine(['trade_name' => 'LORATADINE 10MG ALLERGY', 'generic_name' => 'Loratadine', 'moh_product_id' => 90001, 'dosage_form' => 'Tablet']);
        $cough = $this->mohMedicine(['trade_name' => 'BRONCHOPANE COUGH SYRUP', 'generic_name' => 'Dextromethorphan', 'moh_product_id' => 90002, 'dosage_form' => 'Syrup']);

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

        $this->getJson('/api/categories/medicines/medicines?subcategory=allergy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_name', 'LORATADINE 10MG ALLERGY');

        $this->getJson('/api/categories/medicines/medicines?subcategory=cough-sore-throat')
            ->assertOk()
            ->assertJsonPath('data.0.trade_name', 'BRONCHOPANE COUGH SYRUP');
    }

    public function test_categories_index_exposes_subcategories_with_group_key(): void
    {
        $cats = $this->getJson('/api/categories')->assertOk()->json('data');
        $medCat = collect($cats)->firstWhere('slug', 'medicines');
        $vitsCat = collect($cats)->firstWhere('slug', 'vitamins-supplements');

        // قسم الأدوية — 6 فلاتر أعراض (بالسكرية+الانفلونزا+الأعراض المختلفة)
        $this->assertCount(6, $medCat['subcategories']);
        $this->assertSame('symptoms', $medCat['subcategories'][0]['group_key']);

        // قسم الفيتامينات والمكملات — 4 فيتامينات + 4 مكملات = 8 فلاتر
        $this->assertCount(8, $vitsCat['subcategories'] ?? []);
        $groups = collect($vitsCat['subcategories'])->pluck('group_key')->unique()->all();
        $this->assertSame(['vitamins', 'supplements'], $groups);
    }

    public function test_medicines_endpoint_filters_by_dosage_form_arabic(): void
    {
        $medicinesCat = Category::where('slug', 'medicines')->first();

        $syrup = $this->mohMedicine(['trade_name' => 'BRONCHOPANE SYRUP 200ML', 'moh_product_id' => 90003, 'dosage_form' => 'Syrup']);
        $tablet = $this->mohMedicine(['trade_name' => 'PANADOL TABLET 500', 'moh_product_id' => 90004, 'dosage_form' => 'Tablet']);

        foreach ([$syrup, $tablet] as $moh) {
            CategoryMedicineLink::create([
                'category_id' => $medicinesCat->id,
                'moh_product_id' => $moh->moh_product_id,
                'source' => 'rules',
                'confidence' => 90,
                'needs_review' => false,
            ]);
        }

        // تصفية DOSAGE FORM — بالقيمة الإنجليزية أو العربية (شراب / حبوب ...)
        $this->getJson('/api/categories/medicines/medicines?dosage_form=syrup')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.trade_name', 'BRONCHOPANE SYRUP 200ML');
    }
}
