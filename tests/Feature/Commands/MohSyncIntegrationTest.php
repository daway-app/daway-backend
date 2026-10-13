<?php

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\CategoryMedicineLink;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M-3B — اختبار تكامل: تشغيل `moh:sync` فعليًا ثم التحقق من مسارات المريض.
 *
 * الفجوة التي يغطيها: اختبارات M-3B تُثبت بقاء الصفوف في قاعدة البيانات،
 * لكن لا اختبار يشغّل الأمر ثم يتأكد أن **الوصلات الحيّة** ما زالت تُحِلّ:
 *   · `Api\CategoryController::whereAvailable` يوصل `pm.moh_medicine_id = moh_medicines.id`
 *   · `Api\BarcodeLookupController` يقرأ الصور عبر `medicine_images.moh_medicine_id`
 *
 * 🔴 إثبات تنفيذ الأمر (لا بيانات ثابتة): الأمر يعمل على مصدر MOH **وهمي**
 * عبر `Http::fake()`، والإثبات من ثلاث جهات:
 *   1. رمز الخروج 0 + ظهور سطر النجاح.
 *   2. `Http::assertSentCount(2)` ⇒ نداء المنتجات + نداء الأسعار وقعا فعلًا.
 *   3. الكتابة مشهودة من المصدر (اسم محدَّث + مفتاح دواء مضاف + سعر جديد).
 *
 * العزل: `Http::fake()` + sqlite `:memory:` — بلا شبكة وبلا مصدر حقيقي.
 */
class MohSyncIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ID = 5001;

    private const DRUG_ID = 6001;

    private const BARCODE = '6281000000001';

    private const IMAGE_URL = 'https://example.test/panadol.jpg';

    /** صف منتج كما يفهمه `parseProducts`. */
    private function productRow(string $name, int $productId): array
    {
        return [$name, 'GSK', 'Tablet', 'Analgesic', 'Local', "https://pharmacy.moh.ps/service/ProductsId/{$productId}"];
    }

    /** صف سعر كما يفهمه `parsePrices`. */
    private function priceRow(string $name, int $drugId, string $price): array
    {
        return [$name, 'Paracetamol', $price, 'Box', 'GSK', 'متوفر', "https://pharmacy.moh.ps/service/DrugsId/{$drugId} <span class='flash'>2026-01-01</span>"];
    }

    private function fakeSources(array $products, array $prices): void
    {
        Http::fake([
            '*/getRegisterProducts' => Http::response(['aaData' => $products], 200),
            '*/getDrugsPublic' => Http::response(['aaData' => $prices], 200),
        ]);
    }

    /**
     * كتالوج مربوط بالكامل: صفّ MOH + رابط تصنيف بالمفتاح المستقر + مخزون
     * صيدلية مربوط بـ`moh_medicine_id` + باركود + صورة.
     *
     * @return array{moh:MohMedicine,category:Category,pm:PharmacyMedicine,barcodeId:int,imageId:int}
     */
    private function seedLinkedCatalog(): array
    {
        $moh = MohMedicine::create([
            'trade_name' => 'Panadol',
            'moh_product_id' => self::PRODUCT_ID,
            'official_price' => 5.00,
        ]);

        $category = Category::create([
            'name_ar' => 'مسكنات',
            'name_en' => 'Analgesics',
            'slug' => 'analgesics-'.uniqid(),
            'is_active' => true,
        ]);

        // الرابط بالمفتاح المستقر (لا بالمعرّف الداخلي) — نمط CategoryAvailabilityFilterTest
        CategoryMedicineLink::create([
            'category_id' => $category->id,
            'moh_product_id' => self::PRODUCT_ID,
            'source' => CategoryMedicineLink::SOURCE_PRODUCT_CLASS,
        ]);

        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $moh->id,
            'price' => 7.50,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $barcodeId = DB::table('medicine_barcodes')->insertGetId([
            'moh_medicine_id' => $moh->id,
            'barcode' => self::BARCODE,
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $imageId = DB::table('medicine_images')->insertGetId([
            'moh_medicine_id' => $moh->id,
            'image_url' => self::IMAGE_URL,
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('moh', 'category', 'pm', 'barcodeId', 'imageId');
    }

    private function categoryMedicines(int $categoryId)
    {
        return $this->getJson("/api/categories/{$categoryId}/medicines");
    }

    private function barcodeLookup()
    {
        return $this->getJson('/api/medicines/barcode/'.self::BARCODE);
    }

    // ══════════════════════════════════════════════════════════════════
    // A) مزامنة ناجحة فعلية ⇒ المعرّفات والروابط سليمة ومسارات المريض تعمل
    // ══════════════════════════════════════════════════════════════════

    public function test_a_real_sync_run_keeps_ids_links_and_patient_resolution(): void
    {
        $f = $this->seedLinkedCatalog();

        // قبل المزامنة: مسارا المريض يعملان
        $this->categoryMedicines($f['category']->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.trade_name', 'Panadol');

        $this->barcodeLookup()
            ->assertOk()
            ->assertJsonPath('data.medicine.name_en', 'Panadol')
            ->assertJsonPath('data.image.url', self::IMAGE_URL);

        // مصدر MOH وهمي: نفس المفتاح باسم محدَّث + مفتاح دواء وسعر جديدين
        $this->fakeSources(
            [$this->productRow('Panadol Extra', self::PRODUCT_ID)],
            [$this->priceRow('Panadol Extra', self::DRUG_ID, '9.99')]
        );

        // 🔴 تنفيذ الأمر فعليًا
        $this->artisan('moh:sync')
            ->expectsOutputToContain('تمت المزامنة')
            ->assertExitCode(0);

        // 🔴 إثبات أن المصدر الوهمي استُهلك (نداء المنتجات + نداء الأسعار)
        Http::assertSentCount(2);

        // 🔴 إثبات أن الكتابة جاءت من المصدر (لا بيانات ثابتة)
        $row = MohMedicine::find($f['moh']->id);
        $this->assertNotNull($row, 'صفّ الكتالوج يجب أن يبقى بنفس المعرّف.');
        $this->assertSame('Panadol Extra', $row->trade_name);
        $this->assertSame(self::DRUG_ID, (int) $row->moh_drug_id);
        $this->assertSame('9.99', (string) $row->official_price);

        // ① المعرّف الداخلي ثابت
        $this->assertSame($f['moh']->id, MohMedicine::where('moh_product_id', self::PRODUCT_ID)->value('id'));

        // ② الروابط ثابتة (مخزون + باركود + صورة)
        $this->assertSame($f['moh']->id, (int) $f['pm']->fresh()->moh_medicine_id);
        $this->assertDatabaseHas('medicine_barcodes', ['id' => $f['barcodeId'], 'moh_medicine_id' => $f['moh']->id]);
        $this->assertDatabaseHas('medicine_images', ['id' => $f['imageId'], 'moh_medicine_id' => $f['moh']->id]);

        // ③ فلتر توفر المريض ما زال يُحِلّ (الربط بالمخزون لم يُقطع)
        $this->categoryMedicines($f['category']->id)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.trade_name', 'Panadol Extra');

        // ④ البحث بالباركود ما زال يُحِلّ مع الصورة
        $this->barcodeLookup()
            ->assertOk()
            ->assertJsonPath('data.medicine.name_en', 'Panadol Extra')
            ->assertJsonPath('data.barcode.value', self::BARCODE)
            ->assertJsonPath('data.image.url', self::IMAGE_URL);
    }

    // ══════════════════════════════════════════════════════════════════
    // B) مزامنة مُجهَضة ⇒ لا تغيير في مسارات المريض
    // ══════════════════════════════════════════════════════════════════

    public function test_an_aborted_sync_leaves_the_patient_paths_untouched(): void
    {
        $f = $this->seedLinkedCatalog();

        $beforeCategory = $this->categoryMedicines($f['category']->id)->assertOk()->json();
        $beforeBarcode = $this->barcodeLookup()->assertOk()->json();

        // استجابة صحيحة نحويًا لكنها فارغة ⇒ حاجز M-3B.2a يوقف المزامنة
        $this->fakeSources([], []);

        $this->artisan('moh:sync')->assertExitCode(1);

        Http::assertSentCount(2);

        // لا شيء تغيّر
        $this->assertSame($f['moh']->id, MohMedicine::where('moh_product_id', self::PRODUCT_ID)->value('id'));
        $this->assertSame('Panadol', MohMedicine::find($f['moh']->id)->trade_name);
        $this->assertSame($f['moh']->id, (int) $f['pm']->fresh()->moh_medicine_id);

        $this->assertSame($beforeCategory, $this->categoryMedicines($f['category']->id)->assertOk()->json());
        $this->assertSame($beforeBarcode, $this->barcodeLookup()->assertOk()->json());
    }

    // ══════════════════════════════════════════════════════════════════
    // C) عدّادات الأقسام ثابتة عبر مزامنة ناجحة
    // ══════════════════════════════════════════════════════════════════

    public function test_category_counts_survive_a_real_sync(): void
    {
        $f = $this->seedLinkedCatalog();

        $before = $this->getJson('/api/categories')->assertOk()->json('data');
        $beforeCount = collect($before)->firstWhere('id', $f['category']->id)['medicines_count'] ?? null;

        $this->assertSame(1, $beforeCount, 'قبل المزامنة: دواء واحد متوفر في القسم.');

        $this->fakeSources(
            [$this->productRow('Panadol Extra', self::PRODUCT_ID)],
            [$this->priceRow('Panadol Extra', self::DRUG_ID, '9.99')]
        );

        $this->artisan('moh:sync')->assertExitCode(0);
        Http::assertSentCount(2);

        $after = $this->getJson('/api/categories')->assertOk()->json('data');
        $afterCount = collect($after)->firstWhere('id', $f['category']->id)['medicines_count'] ?? null;

        $this->assertSame($beforeCount, $afterCount, 'عدّاد القسم يجب أن يبقى ثابتًا بعد المزامنة.');
    }
}
