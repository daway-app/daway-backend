<?php

namespace Tests\Feature\Commands;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M-3B.2a — حواجز مصدر `moh:sync` قبل أي كتابة على `moh_medicines`.
 *
 * 🔴 الفجوة التي تُغلَق: `moh:sync` كان ينفّذ `delete-all + insert` فور
 * نجاح HTTP 200 وJSON صالح — **بدون أي تحقق من اكتمال البيانات**. استجابة
 * صحيحة نحويًا لكنها فارغة كانت ستمسح الكتالوج كاملًا وتُدخل لا شيء، وتُقطع
 * روابط مخزون كل الصيدليات (`pharmacy_medicines.moh_medicine_id` ⇒ set null)
 * وتمحو الباركود والصور ومراجعات الإثراء عبر cascade.
 *
 * العقود:
 *   1. أي فشل تحقق يحدث **قبل أول عملية كتابة** ⇒ الكتالوج الحالي يبقى كما هو
 *      (نفس الصفوف، نفس المعرّفات، نفس الروابط).
 *   2. HTTP 200 + JSON صالح **لا** دليل فيهما على اكتمال البيانات — البنية
 *      (`aaData` مصفوفة) وعدم الفراغ شروط لازمتان.
 *   3. الصفوف المرفوضة تُعَدّ وتُعلَن — لا تجاهل صامت.
 *   4. تعارض المفاتيح المستقرة داخل المصدر يُكشف **قبل الكتابة** (الفهارس
 *      الفريدة 2026_09_23 كانت ستُفشل المعاملة في منتصفها).
 *
 * العزل: `Http::fake()` بلا شبكة حقيقية + sqlite `:memory:`.
 */
class MohSyncGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTS_URL = '*/getRegisterProducts';

    private const PRICES_URL = '*/getDrugsPublic';

    /** صف منتج كما يفهمه `parseProducts` (6 عناصر، الأخير يحمل ProductsId). */
    private function productRow(string $name, int $productId): array
    {
        return [$name, 'MFG', 'Tablet', 'Analgesic', 'Local', "https://pharmacy.moh.ps/service/ProductsId/{$productId}"];
    }

    /** صف سعر كما يفهمه `parsePrices` (7 عناصر، السابع يحمل DrugsId). */
    private function priceRow(string $name, int $drugId): array
    {
        return [$name, 'Generic', '12.50', 'Box', 'Company', 'متوفر', "https://pharmacy.moh.ps/service/DrugsId/{$drugId} <span class='flash'>2026-01-01</span>"];
    }

    private function fakeSources(mixed $products, mixed $prices): void
    {
        Http::fake([
            self::PRODUCTS_URL => Http::response($products, 200),
            self::PRICES_URL => Http::response($prices, 200),
        ]);
    }

    private function sync()
    {
        return $this->artisan('moh:sync');
    }

    /** بذرة كتالوج قائم + رابط مخزون يجب أن يبقى سليمًا. */
    private function seedExistingCatalog(): array
    {
        $rowA = MohMedicine::create([
            'trade_name' => 'دواء قائم A',
            'moh_product_id' => 111,
            'moh_drug_id' => 211,
            'official_price' => 10.00,
        ]);

        $rowB = MohMedicine::create([
            'trade_name' => 'دواء قائم B',
            'moh_product_id' => 112,
            'moh_drug_id' => 212,
            'official_price' => 20.00,
        ]);

        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'moh_medicine_id' => $rowA->id,
            'price' => 10.00,
            'quantity' => 5,
            'is_available' => true,
        ]);

        return [$rowA, $rowB, $pm];
    }

    // ══════════════════════════════════════════════════════════════════
    // 1) فشل HTTP
    // ══════════════════════════════════════════════════════════════════

    public function test_http_failure_aborts_before_any_write(): void
    {
        [$rowA, $rowB] = $this->seedExistingCatalog();

        Http::fake([self::PRODUCTS_URL => Http::response('error', 500)]);

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
        $this->assertSame($rowB->id, MohMedicine::find($rowB->id)?->id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2) JSON غير صالح
    // ══════════════════════════════════════════════════════════════════

    public function test_invalid_json_aborts_before_any_write(): void
    {
        [$rowA] = $this->seedExistingCatalog();

        $this->fakeSources('{not-valid-json', ['aaData' => []]);

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3) aaData مفقودة
    // ══════════════════════════════════════════════════════════════════

    public function test_missing_aa_data_aborts_before_any_write(): void
    {
        [$rowA, $rowB] = $this->seedExistingCatalog();

        $this->fakeSources(['status' => 'ok'], ['status' => 'ok']);

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
        $this->assertSame($rowB->id, MohMedicine::find($rowB->id)?->id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 4) aaData ليست مصفوفة
    // ══════════════════════════════════════════════════════════════════

    public function test_aa_data_that_is_not_an_array_aborts_before_any_write(): void
    {
        [$rowA] = $this->seedExistingCatalog();

        $this->fakeSources(['aaData' => 'unexpected string'], ['aaData' => []]);

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 5) مصدر منتجات فارغ
    // ══════════════════════════════════════════════════════════════════

    public function test_empty_products_abort_before_any_write(): void
    {
        [$rowA, $rowB] = $this->seedExistingCatalog();

        $this->fakeSources(
            ['aaData' => []],
            ['aaData' => [$this->priceRow('دواء قائم A', 211)]]
        );

        $this->sync()->assertExitCode(1);

        // 🔴 الاختبار الحمائي: الكتالوج كما كان — نفس الصفوف والمعرّفات
        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
        $this->assertSame($rowB->id, MohMedicine::find($rowB->id)?->id);
        $this->assertSame('دواء قائم A', MohMedicine::find($rowA->id)->trade_name);
        $this->assertSame(111, (int) MohMedicine::find($rowA->id)->moh_product_id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 6) مصدر أسعار فارغ
    // ══════════════════════════════════════════════════════════════════

    public function test_empty_prices_abort_before_any_write(): void
    {
        [$rowA, $rowB] = $this->seedExistingCatalog();

        $this->fakeSources(
            ['aaData' => [$this->productRow('دواء قائم A', 111)]],
            ['aaData' => []]
        );

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
        $this->assertSame($rowB->id, MohMedicine::find($rowB->id)?->id);
    }

    // ══════════════════════════════════════════════════════════════════
    // 7) تعارض المفاتيح المستقرة داخل المصدر — يُكشف قبل الكتابة
    // ══════════════════════════════════════════════════════════════════

    public function test_duplicate_product_id_in_source_aborts_before_any_write(): void
    {
        [$rowA, $rowB] = $this->seedExistingCatalog();

        $this->fakeSources(
            ['aaData' => [
                $this->productRow('دواء جديد 1', 555),
                $this->productRow('دواء جديد 2', 555), // نفس moh_product_id
            ]],
            ['aaData' => [$this->priceRow('دواء جديد 1', 777)]]
        );

        $this->sync()->assertExitCode(1);

        // لا صفوف جديدة ولا حذف للقديم
        $this->assertSame(2, MohMedicine::count());
        $this->assertNotNull(MohMedicine::find($rowA->id));
        $this->assertDatabaseMissing('moh_medicines', ['moh_product_id' => 555]);
    }

    public function test_duplicate_drug_id_in_source_aborts_before_any_write(): void
    {
        [$rowA] = $this->seedExistingCatalog();

        $this->fakeSources(
            ['aaData' => [$this->productRow('دواء جديد', 555)]],
            ['aaData' => [
                $this->priceRow('دواء جديد', 777),
                $this->priceRow('دواء جديد بديل', 777), // نفس moh_drug_id
            ]]
        );

        $this->sync()->assertExitCode(1);

        $this->assertSame(2, MohMedicine::count());
        $this->assertDatabaseMissing('moh_medicines', ['moh_drug_id' => 777]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 8) الصفوف المرفوضة تُعَدّ وتُعلَن — لا تجاهل صامت
    // ══════════════════════════════════════════════════════════════════

    public function test_malformed_rows_are_counted_and_reported(): void
    {
        $this->fakeSources(
            ['aaData' => [
                ['short'],                       // غير مفهوم ⇒ يُعَدّ
                $this->productRow('دواء صالح', 555),
            ]],
            ['aaData' => [
                ['', 'G', '10', 'B', 'C', 'متوفر', "https://pharmacy.moh.ps/service/DrugsId/777"], // اسم فارغ ⇒ يُعَدّ
                $this->priceRow('دواء صالح', 777),
            ]]
        );

        $this->sync()
            ->assertExitCode(0)
            ->expectsOutputToContain('تم تجاهل 1 صفًّا من المستحضرات و 1 صفًّا من الأسعار');

        $this->assertSame(1, MohMedicine::count());
        $this->assertNotNull(MohMedicine::where('moh_product_id', 555)->first());
    }

    // ══════════════════════════════════════════════════════════════════
    // 9) المسار السليم — الحواجز لا تعطّل المزامنة الصحيحة
    // ══════════════════════════════════════════════════════════════════

    public function test_a_valid_response_still_syncs_normally(): void
    {
        $this->fakeSources(
            ['aaData' => [$this->productRow('دواء صالح', 555)]],
            ['aaData' => [$this->priceRow('دواء صالح', 777)]]
        );

        $this->sync()->assertExitCode(0);

        $row = MohMedicine::where('moh_product_id', 555)->first();
        $this->assertNotNull($row);
        $this->assertSame(777, (int) $row->moh_drug_id);
        $this->assertSame('دواء صالح', $row->trade_name);
    }

    // ══════════════════════════════════════════════════════════════════
    // 10) الحماية الأساسية — الكتالوج + رابط المخزون يبقيان
    // ══════════════════════════════════════════════════════════════════

    /** 🔴 الاختبار الحمائي المطلوب: استجابة غير صالحة لا تُغيّر شيئًا. */
    public function test_an_invalid_response_keeps_the_existing_catalog_and_its_pharmacy_link(): void
    {
        [$rowA, $rowB, $pm] = $this->seedExistingCatalog();

        // استجابة نحويًا صحيحة لكنها فارغة — السيناريو الأخطر
        $this->fakeSources(['aaData' => []], ['aaData' => []]);

        $this->sync()->assertExitCode(1);

        // الكتالوج: نفس الصفوف، نفس المعرّفات الداخلية، نفس الأسماء
        $this->assertSame(2, MohMedicine::count());
        $this->assertSame($rowA->id, MohMedicine::find($rowA->id)?->id);
        $this->assertSame($rowB->id, MohMedicine::find($rowB->id)?->id);
        $this->assertSame('دواء قائم A', MohMedicine::find($rowA->id)->trade_name);
        $this->assertSame(211, (int) MohMedicine::find($rowA->id)->moh_drug_id);

        // رابط مخزون الصيدلية: moh_medicine_id **لم يُقطع** (لم يصير null)
        $this->assertSame($rowA->id, (int) $pm->fresh()->moh_medicine_id);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pm->id,
            'moh_medicine_id' => $rowA->id,
        ]);
    }
}
