<?php

namespace Tests\Feature\Commands;

use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M-3B.2b — المطابقة بالمفاتيح المستقرة + upsert موضعي في `moh:sync`.
 *
 * الفرق الجوهري عن السابق: **لا `delete-all` ولا `insert` جماعي** ⇒
 * `moh_medicines.id` يبقى ثابتًا ⇒ لا cascade على barcodes/images/reviews
 * ولا قطع لـ`pharmacy_medicines.moh_medicine_id`.
 *
 * قواعد الهوية المُختبَرة:
 *   1. المطابقة بـ`moh_product_id`/`moh_drug_id` — **لا بالاسم**.
 *   2. المفتاحان لسجلين مختلفين ⇒ رفض قبل أي كتابة.
 *   3. تعارض بيانات (مفتاح مستقر بقيمة مختلفة) ⇒ رفض.
 *   4. اسم متطابق بمعرّفات مختلفة ⇒ **سجلان منفصلان** (لا طمس).
 *   5. الغائب عن الاستجابة ⇒ يُبقى بلا تغيير ويُعَدّ.
 *   6. القفل يمنع مزامنة متزامنة.
 *
 * ⚠️ حدّ اختبار القفل: يُثبت **منطق الحارس** مع driver الاختبار (`array`)؛
 * لا يُثبت تزامنًا بين عمليتين منفصلتين على نظام ملفات حقيقي.
 */
class MohSyncUpsertTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTS_URL = '*/getRegisterProducts';

    private const PRICES_URL = '*/getDrugsPublic';

    private function productRow(string $name, int $productId, string $manufacturer = 'MFG'): array
    {
        return [$name, $manufacturer, 'Tablet', 'Analgesic', 'Local', "https://pharmacy.moh.ps/service/ProductsId/{$productId}"];
    }

    private function priceRow(string $name, int $drugId, string $price = '12.50'): array
    {
        return [$name, 'Generic', $price, 'Box', 'Company', 'متوفر', "https://pharmacy.moh.ps/service/DrugsId/{$drugId} <span class='flash'>2026-01-01</span>"];
    }

    /**
     * صفّ سعر بلا `DrugsId` ⇒ `parsePrices` يُنتج `moh_drug_id = null`.
     * (M-3B.2b.1: هذه الحالة كانت تسمح بإعادة استخدام صفّ المنتج.)
     */
    private function priceRowWithoutDrugId(string $name, string $price): array
    {
        return [$name, 'Generic', $price, 'Box', 'Company', 'متوفر', 'https://pharmacy.moh.ps/service/unknown/999'];
    }

    private function fakeSources(array $products, array $prices): void
    {
        Http::fake([
            self::PRODUCTS_URL => Http::response(['aaData' => $products], 200),
            self::PRICES_URL => Http::response(['aaData' => $prices], 200),
        ]);
    }

    private function sync()
    {
        return $this->artisan('moh:sync');
    }

    // ══════════════════════════════════════════════════════════════════
    // 1) تحديث بالمفتاح يحفظ الـid
    // ══════════════════════════════════════════════════════════════════

    public function test_updating_an_existing_record_by_key_preserves_its_id(): void
    {
        $existing = MohMedicine::create([
            'trade_name' => 'Panadol',
            'moh_product_id' => 100,
            'manufacturer' => 'OLD-MFG',
        ]);

        $this->fakeSources([$this->productRow('Panadol', 100, 'NEW-MFG')], [$this->priceRow('Panadol', 200)]);

        $this->sync()->assertExitCode(0);

        $this->assertSame(1, MohMedicine::count());
        $row = MohMedicine::find($existing->id);

        $this->assertNotNull($row, 'السجل الأصلي يجب أن يبقى بنفس المعرّف.');
        $this->assertSame('NEW-MFG', $row->manufacturer);
        $this->assertSame(200, (int) $row->moh_drug_id, 'مفتاح الدواء يُضاف على نفس السجل.');
    }

    // ══════════════════════════════════════════════════════════════════
    // 2) تغيير الاسم لا يُنشئ سجلًا مكررًا
    // ══════════════════════════════════════════════════════════════════

    public function test_a_trade_name_change_does_not_create_a_duplicate(): void
    {
        $existing = MohMedicine::create([
            'trade_name' => 'الاسم القديم',
            'moh_product_id' => 101,
        ]);

        $this->fakeSources([$this->productRow('الاسم الجديد', 101)], [$this->priceRow('الاسم الجديد', 201)]);

        $this->sync()->assertExitCode(0);

        $this->assertSame(1, MohMedicine::count());
        $this->assertSame($existing->id, MohMedicine::first()->id);
        $this->assertSame('الاسم الجديد', MohMedicine::find($existing->id)->trade_name);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3) اسم متطابق بمعرّفات مختلفة ⇒ سجلان منفصلان (لا طمس)
    //    M-3B.2b.2: ولا إسناد تخميني للسعر
    // ══════════════════════════════════════════════════════════════════

    public function test_same_name_with_different_keys_creates_two_separate_records(): void
    {
        // نفس الاسم المطبَّع تمامًا، معرّفا منتج مختلفان + صفّ سعر واحد
        $this->fakeSources(
            [
                $this->productRow('Panadol', 301),
                $this->productRow('Panadol', 302),
            ],
            [$this->priceRow('Panadol', 401)]
        );

        $this->sync()
            ->assertExitCode(0)
            ->expectsOutputToContain('لالتباس الاسم');

        $this->assertSame(2, MohMedicine::count(), 'الاسم ليس هوية — يجب ألا يطمس صفًّا.');

        $first = MohMedicine::where('moh_product_id', 301)->first();
        $second = MohMedicine::where('moh_product_id', 302)->first();

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        // 🔴 M-3B.2b.2: لا يُسنَد السعر لأي منهما (كان يُسنَد لأول صفّ بترتيب المصدر)
        $this->assertNull($first->moh_drug_id, 'لا إسناد تخميني لأول منتج.');
        $this->assertNull($second->moh_drug_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3.b) M-3B.2b.1/B.2b.2 — غياب drug id + الالتباس
    // ══════════════════════════════════════════════════════════════════

    /**
     * سعران بنفس الاسم لمنتج واحد ⇒ التباس (أي سعر يخصّ المنتج؟) ⇒ لا إسناد.
     * ويبقى إصلاح M-3B.2b.1 (`$consumedProductRows`) حارسًا دفاعيًا.
     */
    public function test_two_price_rows_for_one_product_are_not_attributed(): void
    {
        $this->fakeSources(
            [$this->productRow('Panadol', 2001)],
            [
                $this->priceRowWithoutDrugId('Panadol', '11.00'),
                $this->priceRowWithoutDrugId('Panadol', '22.00'),
            ]
        );

        $this->sync()
            ->assertExitCode(0)
            ->expectsOutputToContain('لالتباس الاسم');

        // صفّ المنتج فقط — لا إسناد لأي من السعرين، ولا طمس
        $this->assertSame(1, MohMedicine::count());
        $product = MohMedicine::where('moh_product_id', 2001)->first();
        $this->assertNotNull($product);
        $this->assertNull($product->moh_drug_id);
        $this->assertNull($product->official_price, 'لا سعر مُخمَّن على المنتج.');
    }

    /** منتجان + سعران بنفس الاسم ⇒ لا توزيع حسب ترتيب المصدر. */
    public function test_two_product_rows_and_two_price_rows_are_not_distributed_by_order(): void
    {
        $this->fakeSources(
            [
                $this->productRow('Panadol', 2101),
                $this->productRow('Panadol', 2102),
            ],
            [
                $this->priceRow('Panadol', 2201),
                $this->priceRow('Panadol', 2202),
            ]
        );

        $this->sync()
            ->assertExitCode(0)
            ->expectsOutputToContain('لالتباس الاسم');

        // المنتجان يبقيان (لا دمج)، ولا سعر يُسنَد لأيٍّ منهما
        $this->assertSame(2, MohMedicine::count());
        $this->assertNull(MohMedicine::where('moh_product_id', 2101)->value('moh_drug_id'));
        $this->assertNull(MohMedicine::where('moh_product_id', 2102)->value('moh_drug_id'));
    }

    /** عكس ترتيب صفوف الأسعار لا يغيّر شيئًا (لا إسناد في الحالتين). */
    public function test_reversing_price_order_does_not_produce_attribution(): void
    {
        $this->fakeSources(
            [
                $this->productRow('Panadol', 2301),
                $this->productRow('Panadol', 2302),
            ],
            [
                $this->priceRow('Panadol', 2402), // ترتيب معكوس
                $this->priceRow('Panadol', 2401),
            ]
        );

        $this->sync()->assertExitCode(0);

        $this->assertSame(2, MohMedicine::count());
        $this->assertNull(MohMedicine::where('moh_product_id', 2301)->value('moh_drug_id'));
        $this->assertNull(MohMedicine::where('moh_product_id', 2302)->value('moh_drug_id'));
    }

    /**
     * حالات واضحة وملتبسة معًا: الواضحة تُربط، الملتبسة تُتخطّى — دون أن
     * تُفسد الحالة الملتبسة بقية المزامنة.
     */
    public function test_ambiguous_and_clear_cases_coexist_safely(): void
    {
        $this->fakeSources(
            [
                $this->productRow('Panadol', 2501),
                $this->productRow('Panadol', 2502), // ملتبس
                $this->productRow('Brufen', 2601),  // واضح
            ],
            [
                $this->priceRow('Panadol', 2701),   // ملتبس ⇒ يُتخطّى
                $this->priceRow('Brufen', 2801),    // واضح ⇒ يُربط
            ]
        );

        $this->sync()->assertExitCode(0);

        // الواضح رُبط
        $this->assertSame(2801, (int) MohMedicine::where('moh_product_id', 2601)->value('moh_drug_id'));

        // الملتبس لم يُسنَد
        $this->assertNull(MohMedicine::where('moh_product_id', 2501)->value('moh_drug_id'));
        $this->assertNull(MohMedicine::where('moh_product_id', 2502)->value('moh_drug_id'));

        // ولا صفّ سعر ملتبس أُنشئ كسجلّ مستقل
        $this->assertSame(3, MohMedicine::count());
        $this->assertDatabaseMissing('moh_medicines', ['moh_drug_id' => 2701]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 4) المفتاحان يشيران لسجلين مختلفين ⇒ رفض بلا كتابة
    // ══════════════════════════════════════════════════════════════════

    public function test_conflicting_keys_pointing_to_different_records_abort_before_any_write(): void
    {
        $rowA = MohMedicine::create(['trade_name' => 'A', 'moh_product_id' => 401]);
        $rowB = MohMedicine::create(['trade_name' => 'B', 'moh_drug_id' => 501]);

        // صفّ مصدر واحد يحمل كلا المفتاحين، وكلٌّ يشير لسجل مختلف
        $this->fakeSources([$this->productRow('X', 401)], [$this->priceRow('X', 501)]);

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count(), 'لا إنشاء ولا حذف عند التعارض.');
        $this->assertSame('A', MohMedicine::find($rowA->id)->trade_name);
        $this->assertSame('B', MohMedicine::find($rowB->id)->trade_name);
        $this->assertNull(MohMedicine::find($rowA->id)->moh_drug_id, 'لا إعادة ربط تلقائية.');
    }

    // ══════════════════════════════════════════════════════════════════
    // 5) تكرار المفاتيح داخل المصدر ⇒ رفض بلا كتابة
    // ══════════════════════════════════════════════════════════════════

    public function test_duplicate_drug_id_in_source_aborts_before_any_write(): void
    {
        $existing = MohMedicine::create(['trade_name' => 'موجود', 'moh_product_id' => 601]);

        $this->fakeSources(
            [$this->productRow('أ', 701)],
            [
                $this->priceRow('أ', 801),
                $this->priceRow('ب', 801), // نفس moh_drug_id
            ]
        );

        $this->sync()->assertExitCode(1);

        $this->assertSame(1, MohMedicine::count());
        $this->assertNotNull(MohMedicine::find($existing->id));
        $this->assertDatabaseMissing('moh_medicines', ['moh_drug_id' => 801]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 6) سجل جديد بمفتاح غير متعارض
    // ══════════════════════════════════════════════════════════════════

    public function test_a_new_record_with_an_unconflicting_key_is_created(): void
    {
        $this->fakeSources([$this->productRow('جديد', 901)], [$this->priceRow('جديد', 902)]);

        $this->sync()->assertExitCode(0);

        $row = MohMedicine::where('moh_product_id', 901)->first();
        $this->assertNotNull($row);
        $this->assertSame(902, (int) $row->moh_drug_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 7) الغائب عن الاستجابة يبقى بلا تغيير
    // ══════════════════════════════════════════════════════════════════

    public function test_a_record_absent_from_the_source_is_kept_unchanged(): void
    {
        $absent = MohMedicine::create([
            'trade_name' => 'دواء غائب',
            'moh_product_id' => 1001,
            'official_price' => 55.00,
        ]);

        $this->fakeSources([$this->productRow('حاضر', 1002)], [$this->priceRow('حاضر', 1003)]);

        $this->sync()
            ->assertExitCode(0)
            ->expectsOutputToContain('لم يظهر في استجابة المصدر');

        $kept = MohMedicine::find($absent->id);
        $this->assertNotNull($kept, '🔴 الغياب ليس دليل حذف.');
        $this->assertSame('دواء غائب', $kept->trade_name);
        $this->assertSame('55.00', (string) $kept->official_price);
        $this->assertSame(2, MohMedicine::count());
    }

    // ══════════════════════════════════════════════════════════════════
    // 8) استجابة متعارضة لا تغيّر الكتالوج
    // ══════════════════════════════════════════════════════════════════

    public function test_a_conflicting_response_does_not_change_the_catalog(): void
    {
        $rowA = MohMedicine::create(['trade_name' => 'A', 'moh_product_id' => 1101, 'manufacturer' => 'MA']);
        $rowB = MohMedicine::create(['trade_name' => 'B', 'moh_drug_id' => 1102, 'manufacturer' => 'MB']);

        $this->fakeSources([$this->productRow('تعارض', 1101)], [$this->priceRow('تعارض', 1102)]);

        $this->sync()->assertExitCode(1);

        $this->assertSame('A', MohMedicine::find($rowA->id)->trade_name);
        $this->assertSame('MA', MohMedicine::find($rowA->id)->manufacturer);
        $this->assertSame('B', MohMedicine::find($rowB->id)->trade_name);
        $this->assertSame('MB', MohMedicine::find($rowB->id)->manufacturer);
        $this->assertSame(2, MohMedicine::count());
    }

    // ══════════════════════════════════════════════════════════════════
    // 9) العلاقات تبقى سليمة عند تحديث سجل موجود
    // ══════════════════════════════════════════════════════════════════

    public function test_related_records_survive_an_in_place_update(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'قديم', 'moh_product_id' => 1201]);
        $mohId = $moh->id;

        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $mohId,
            'price' => 10, 'quantity' => 5, 'is_available' => true,
        ]);

        $barcodeId = DB::table('medicine_barcodes')->insertGetId([
            'moh_medicine_id' => $mohId, 'barcode' => '6281000000001', 'source' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $imageId = DB::table('medicine_images')->insertGetId([
            'moh_medicine_id' => $mohId, 'image_url' => 'https://example.test/i.jpg', 'source' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reviewId = DB::table('medicine_enrichment_reviews')->insertGetId([
            'moh_medicine_id' => $mohId, 'provider' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = MedicineRequest::create([
            'pharmacy_id' => $pharmacy->id,
            'requested_by' => $user->id,
            'status' => MedicineRequest::STATUS_APPROVED,
            'trade_name' => 'قديم',
            'category_id' => \App\Models\Category::create([
                'name_ar' => 'ق', 'name_en' => 'C', 'slug' => 'c-'.uniqid(), 'is_active' => true,
            ])->id,
            'approved_moh_medicine_id' => $mohId,
        ]);

        // تحديث موضعي: نفس المفتاح باسم جديد
        $this->fakeSources([$this->productRow('جديد', 1201)], [$this->priceRow('جديد', 1202)]);

        $this->sync()->assertExitCode(0);

        // 🔴 نفس المعرّف الداخلي
        $this->assertSame($mohId, MohMedicine::where('moh_product_id', 1201)->value('id'));
        $this->assertSame('جديد', MohMedicine::find($mohId)->trade_name);

        // 🔴 كل العلاقات كما هي — لا cascade ولا قطع
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pm->id, 'moh_medicine_id' => $mohId]);
        $this->assertDatabaseHas('medicine_barcodes', ['id' => $barcodeId, 'moh_medicine_id' => $mohId]);
        $this->assertDatabaseHas('medicine_images', ['id' => $imageId, 'moh_medicine_id' => $mohId]);
        $this->assertDatabaseHas('medicine_enrichment_reviews', ['id' => $reviewId, 'moh_medicine_id' => $mohId]);
        $this->assertSame($mohId, (int) $request->fresh()->approved_moh_medicine_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 10) كل التحقق قبل أول كتابة
    // ══════════════════════════════════════════════════════════════════

    public function test_validation_failures_happen_before_the_first_write(): void
    {
        MohMedicine::create(['trade_name' => 'A', 'moh_product_id' => 1301]);
        MohMedicine::create(['trade_name' => 'B', 'moh_drug_id' => 1302]);

        $before = MohMedicine::query()->orderBy('id')->get(['id', 'trade_name', 'moh_product_id', 'moh_drug_id'])->toArray();

        $this->fakeSources([$this->productRow('تعارض', 1301)], [$this->priceRow('تعارض', 1302)]);
        $this->sync()->assertExitCode(1);

        $after = MohMedicine::query()->orderBy('id')->get(['id', 'trade_name', 'moh_product_id', 'moh_drug_id'])->toArray();

        $this->assertSame($before, $after, '🔴 صفر كتابة عند فشل التحقق.');
    }

    // ══════════════════════════════════════════════════════════════════
    // 11) القفل يمنع مزامنة متزامنة
    // ══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ يُثبت **منطق الحارس**: عندما يكون المفتاح محتجزًا في نفس مخزن
     * الكاش، يُرفض الأمر بلا كتابة. لا يُثبت تزامن عمليتين منفصلتين.
     */
    public function test_a_second_sync_is_refused_while_the_lock_is_held(): void
    {
        $existing = MohMedicine::create(['trade_name' => 'A', 'moh_product_id' => 1401]);

        $this->fakeSources([$this->productRow('A', 1401)], [$this->priceRow('A', 1402)]);

        $lock = Cache::lock('moh-sync', 900);
        $this->assertTrue($lock->get(), 'يجب أن يُمنح القفل في الاختبار.');

        try {
            $this->sync()
                ->assertExitCode(1)
                ->expectsOutputToContain('مزامنة أخرى قيد التشغيل');
        } finally {
            $lock->release();
        }

        // لم تُكتب أي بيانات
        $this->assertSame(1, MohMedicine::count());
        $this->assertNull(MohMedicine::find($existing->id)->moh_drug_id);
    }

    public function test_sync_succeeds_after_the_lock_is_released(): void
    {
        MohMedicine::create(['trade_name' => 'A', 'moh_product_id' => 1501]);

        $this->fakeSources([$this->productRow('A', 1501)], [$this->priceRow('A', 1502)]);

        $lock = Cache::lock('moh-sync', 900);
        $lock->get();
        $lock->release();

        $this->sync()->assertExitCode(0);

        $this->assertSame(1502, (int) MohMedicine::where('moh_product_id', 1501)->value('moh_drug_id'));
    }
}
