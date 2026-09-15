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
 * دورة التصنيف الكاملة (classify:moh-catalog) بعد STEP C:
 *  - الأقسام الفرعية تُربط بمفاتيح مستقرة، ومحصورة داخل Food Supplement
 *  - idempotent: تشغيل متكرر لا يكرّر ولا يغيّر النتيجة
 *  - روابط admin محميّة على مستوى القسم الفرعي أيضاً
 */
class ClassifyMohCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CategorySeeder::class);
    }

    private function moh(array $attributes = []): MohMedicine
    {
        static $seq = 90000;
        $seq++;

        return MohMedicine::create($attributes + [
            'trade_name' => 'CLASSIFY MED '.$seq,
            'moh_product_id' => $seq,
        ]);
    }

    public function test_classify_writes_subcategory_links(): void
    {
        $this->moh([
            'trade_name' => 'Biotin Hair Growth Syrup',
            'generic_name' => 'Biotin',
            'product_class' => 'Food Supplement',
            'dosage_form' => 'Oral syrup',
            'moh_product_id' => 91001,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $med = MohMedicine::where('moh_product_id', 91001)->firstOrFail();
        $this->assertSame('Biotin Hair Growth Syrup', $med->trade_name);

        // Food Supplement ⇒ vitamins-supplements (STEP A) + قسم فرعي فيتامينات الشعر
        $vitaminsCat = Category::where('slug', 'vitamins-supplements')->firstOrFail();
        $hairSub = Subcategory::where('slug', 'hair-vitamins')->firstOrFail();

        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $vitaminsCat->id,
            'subcategory_id' => null,
            'moh_product_id' => 91001,
        ]);
        $this->assertDatabaseHas('category_medicine_links', [
            'category_id' => $vitaminsCat->id,
            'subcategory_id' => $hairSub->id,
            'moh_product_id' => 91001,
        ]);
    }

    public function test_classify_does_not_create_subcategory_links_for_cosmetics(): void
    {
        // 🔴 حماية الانحدار: القواعد النصية وحدها كانت تطابق شامبو/كريم/سيروم
        // (Cosmetic Products) وتضعها تحت "فيتامينات الشعر" — حصر Food Supplement
        // هو الحل، وهذا الاختبار يحرسه.
        $this->moh([
            'trade_name' => 'Rosemary Hair Shampoo',
            'generic_name' => 'Rosemary Extract',
            'product_class' => 'Cosmetic Products',
            'dosage_form' => 'Shampoo',
            'moh_product_id' => 91002,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        // رابط القسم الرئيسي (skin-care-beauty من STEP A) قائم، لكن لا رابط فرعي
        $this->assertSame(
            0,
            CategoryMedicineLink::whereNotNull('subcategory_id')->where('moh_product_id', 91002)->count()
        );
    }

    public function test_classify_does_not_create_subcategory_links_for_medical_devices(): void
    {
        // جهاز طبي باسم يحمل كلمة مطابقة ("Vitamin" أو "Hair") لا يصبح مكمّلاً
        $this->moh([
            'trade_name' => 'Hair Regrowth Laser Device',
            'product_class' => 'Medical Devices',
            'moh_product_id' => 91003,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame(
            0,
            CategoryMedicineLink::whereNotNull('subcategory_id')->where('moh_product_id', 91003)->count()
        );
    }

    public function test_classify_links_food_supplement_to_matching_subcategory(): void
    {
        // الوجه المقابل: نفس الاسم تحت Food Supplement ⇒ الرابط الفرعي يُنشأ
        $this->moh([
            'trade_name' => 'Biotin Hair Complex',
            'product_class' => 'Food Supplement',
            'moh_product_id' => 91004,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $hairSub = Subcategory::where('slug', 'hair-vitamins')->firstOrFail();
        $this->assertDatabaseHas('category_medicine_links', [
            'subcategory_id' => $hairSub->id,
            'moh_product_id' => 91004,
        ]);
    }

    public function test_classify_is_idempotent_for_subcategories(): void
    {
        $this->moh([
            'trade_name' => 'Omega 3 Fish Oil Supplement',
            'generic_name' => 'Omega 3',
            'product_class' => 'Food Supplement',
            'moh_product_id' => 92001,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();
        $firstCount = CategoryMedicineLink::count();
        $firstSub = CategoryMedicineLink::whereNotNull('subcategory_id')->count();

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame($firstCount, CategoryMedicineLink::count());
        $this->assertSame($firstSub, CategoryMedicineLink::whereNotNull('subcategory_id')->count());

        // ربط الأوميغا تحديداً
        $omegaSub = Subcategory::where('slug', 'omega-supplements')->firstOrFail();
        $this->assertDatabaseHas('category_medicine_links', [
            'subcategory_id' => $omegaSub->id,
            'moh_product_id' => 92001,
        ]);
    }

    public function test_classify_respects_admin_links_on_subcategory_level(): void
    {
        $this->moh([
            'trade_name' => 'Protein Whey Powder',
            'generic_name' => 'Whey Protein',
            'product_class' => 'Food Supplement',
            'moh_product_id' => 93001,
        ]);

        // الأقسام الفرعية تُبذر داخل دورة التصنيف نفسها — نبذرها هنا صراحةً
        // لأن الاختبار يحتاجها *قبل* تشغيل الأمر لإنشاء قرار الأدمن.
        $this->seed(SubcategorySeeder::class);

        $vitaminsCat = Category::where('slug', 'vitamins-supplements')->firstOrFail();
        $proteinSub = Subcategory::where('slug', 'protein-supplements')->firstOrFail();

        // قرار أدمن على مستوى القسم الفرعي بثقة مميزة
        CategoryMedicineLink::create([
            'category_id' => $vitaminsCat->id,
            'subcategory_id' => $proteinSub->id,
            'moh_product_id' => 93001,
            'source' => 'admin',
            'confidence' => 100,
            'needs_review' => false,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $link = CategoryMedicineLink::where('subcategory_id', $proteinSub->id)
            ->where('moh_product_id', 93001)
            ->firstOrFail();

        $this->assertSame('admin', $link->source);
        $this->assertSame(100, $link->confidence);
        $this->assertSame(1, CategoryMedicineLink::where('subcategory_id', $proteinSub->id)->where('moh_product_id', 93001)->count());
    }

    public function test_classify_seeds_subcategories_without_manual_step(): void
    {
        $this->assertSame(0, Subcategory::count());

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        // بذر تلقائي داخل الدورة — لا خطوة يدوية على بيئة جديدة
        $this->assertSame(count(SubcategorySeeder::matchRules()), Subcategory::count());
    }

    public function test_classify_does_not_link_subcategory_without_stable_key(): void
    {
        // صف بلا moh_product_id ولا moh_drug_id ⇒ لا روابط فرعية إطلاقاً
        MohMedicine::create([
            'trade_name' => 'Biotin No Key',
            'product_class' => 'Food Supplement',
            'moh_product_id' => null,
            'moh_drug_id' => null,
        ]);

        $this->artisan('classify:moh-catalog')->assertSuccessful();

        $this->assertSame(0, CategoryMedicineLink::whereNotNull('subcategory_id')->count());
    }
}
