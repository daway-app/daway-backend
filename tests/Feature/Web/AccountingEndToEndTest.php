<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تحقّق شامل من طرف إلى طرف (End-to-End) — سيناريو الصيدلي الحقيقي.
 *
 * هذا الملف يجيب على سؤال واحد: **هل تعمل السلسلة كاملة؟**
 *   تصفّح → بحث/مسح → سلة → حفظ عبر الـAPI → خصم مخزون → فاتورة → قائمة.
 *
 * كل خطوة تُتحقَّق من **قاعدة البيانات** لا من HTML فقط، فالعطل الصامت
 * (حفظ يبدو ناجحًا بلا أثر) هو أخطر ما في هذه الوحدة.
 *
 * ── ملاحظات عقد مؤكَّدة (كلها كانت أخطاء في نسخة سابقة من هذا الملف) ──
 *  1. رصيد الصندوق في `stats.balance_now` على **جذر** الاستجابة —
 *     `stats` شقيق لـ`data` لا متداخل معه. كتابة `data.stats.balance_now`
 *     تُرجع null وتمرّ كذبًا لأن `(float) null === 0.0`.
 *  2. رصيد العميل في `data.current_balance` — **لا** `data.balance`.
 *  3. ملخّص المبيعات على `GET .../accounting/sales-summary` (بشرطة)،
 *     والنتيجة في `data.summary.count`. كتابة `sales/summary` تلتقطها
 *     `sales/{number}` فتُرجع 404 «الفاتورة غير موجودة».
 *  4. رفض مخزون الغير = 422 بجسم مخصّص
 *     `{success:false, data:{invalid_pharmacy_medicine_ids:[...]}}`،
 *     **لا** `errors.items` (ليس ValidationException).
 *  5. `@json` في Blade يرمّز غير-ASCII إلى `\uXXXX` ⇒ أي اسم عربي داخل
 *     JSON مضمَّن في الصفحة لا يظهر نصًّا صريحًا. استخدم `bladeJson()`.
 *  6. `json_encode(36.0)` بلا `JSON_PRESERVE_ZERO_FRACTION` يُنتج `36`
 *     (عدد صحيح في الـJSON) ⇒ `assertJsonPath('…', 36.0)` يفشل بالهوية.
 *     قارن بعد `(float)`.
 */
class AccountingEndToEndTest extends TestCase
{
    private User $user;
    private Pharmacy $pharmacy;
    private int $pmId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->pharmacy()->create();
        $this->pharmacy = Pharmacy::factory()->create([
            'user_id' => $this->user->id,
            'delivered_at' => now(),
        ]);

        $medicine = Medicine::factory()->create([
            'trade_name' => 'أموكسيسيلين 500mg',
            'trade_name_ar' => 'أموكسيسيلين 500mg',
        ]);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 12.50,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $this->pmId = (int) $pm->id;
        $this->medicineId = (int) $medicine->id;

        Sanctum::actingAs($this->user);
    }

    /**
     * الشكل الذي يكتبه `@json` فعليًا داخل الصفحة (غير-ASCII → `\uXXXX`).
     *
     * نستخدمه بدل النصّ الخام لأن أي اسم عربي يُضمَّن في JSON عبر `@json`
     * لا يظهر نصًّا مقروءًا في الـHTML، فالـassert على النصّ الخام يفشل
     * رغم أن البيانات موجودة وسليمة.
     */
    private function bladeJson(string $value): string
    {
        return substr(json_encode($value), 1, -1);
    }

    /** السلسلة الكاملة: مخزون → بيع → خصم → فاتورة → قائمة. */
    public function test_full_pharmacist_workflow_from_stock_to_invoice(): void
    {
        // ── 1) شاشة البيع تعرض المخزون الحقيقي ────────────────────────
        $pos = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        // الاسم داخل catalog JSON ⇒ مرمّز. نفحص الشكل المرمّز.
        $this->assertStringContainsString($this->bladeJson('أموكسيسيلين 500mg'), $pos);

        // ── 2) حفظ فاتورة عبر الـAPI (نفس ما تفعله الشاشة) ───────────
        $created = $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_id' => $this->medicineId,
                'medicine_name' => 'أموكسيسيلين 500mg',
                'quantity' => 3,
                'unit_price' => 12.50,
                'line_discount' => 1.50,
            ]],
            'payment_method' => 'cash',
            'paid' => 36.00,
        ])->assertCreated()->json('data');

        $number = $created['number'];
        $this->assertNotEmpty($number);

        // ── 3) الخصم حدث فعلًا (الكمية 10 → 7) ───────────────────────
        $this->assertSame(7, (int) PharmacyMedicine::find($this->pmId)->quantity);

        // ── 4) الحساب صحيح: 3 × 12.50 − 1.50 = 36.00 ────────────────
        $this->assertSame(36.0, (float) $created['total']);

        // ── 5) الفاتورة تظهر في قائمة المبيعات ───────────────────────
        $list = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($number, $list);
        $this->assertStringNotContainsString('class="ph-empty"', $list);

        // ── 6) صفحة الفاتورة تعرض البنود الحقيقية ────────────────────
        $invoice = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/'.$number)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('أموكسيسيلين 500mg', $invoice);
        $this->assertStringNotContainsString(__('accounting.invoice.items_pending'), $invoice);

        // ── 7) النظرة العامة ترى الفاتورة (KPI حقيقي) ────────────────
        $overview = $this->actingAs($this->user)
            ->get('/pharmacy/accounting')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ac-kpi-value', $overview);

        // ── 8) الـAPI يرى نفس الفاتورة ──────────────────────────────
        // ⚠️ `assertJsonPath` يقارن بالهوية (identical) والـJSON يُنتج `36`
        // لا `36.0` — لأن `json_encode(36.0)` بلا `JSON_PRESERVE_ZERO_FRACTION`
        // يُسقط الكسر الصفري. القيمة الرقمية صحيحة، فالمقارنة تتم بعد cast.
        $apiSale = $this->getJson('/api/pharmacy/accounting/sales/'.$number)
            ->assertOk()
            ->json('data');

        $this->assertSame(36.0, (float) $apiSale['total']);

        // ── 9) الصندوق سُجّل فيه المبلغ ─────────────────────────────
        // ⚠️ `stats` **شقيق** لـ`data` في الاستجابة (لا متداخل معه):
        //    { success, message, data:[movements…], pagination, stats, period }
        // كتابة `data.stats.balance_now` تُرجع null وتمرّ كذبًا مع (float).
        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk()->json();

        $this->assertArrayHasKey('stats', $cash);
        $this->assertSame(36.0, (float) $cash['stats']['balance_now']);
    }

    /** البيع الآجل: يُنشئ دينًا حقيقيًا على عميل حقيقي بلا حركة صندوق. */
    public function test_credit_sale_creates_real_debt_and_no_cash(): void
    {
        $customer = $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'أبو محمد',
            'phone' => '0599123456',
        ])->assertCreated()->json('data');

        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_id' => $this->medicineId,
                'medicine_name' => 'أموكسيسيلين 500mg',
                'quantity' => 2,
                'unit_price' => 12.50,
            ]],
            'payment_method' => 'credit',
            'customer_id' => $customer['id'],
        ])->assertCreated();

        // دين حقيقي على العميل — المفتاح `current_balance` لا `balance`.
        $customerData = $this->getJson('/api/pharmacy/accounting/customers/'.$customer['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame(25.0, (float) $customerData['current_balance']);

        // ولا حركة صندوق (البيع الآجل لا يُدخل نقدًا).
        // `stats` شقيق لـ`data` ⇒ نقرأ من الجذر. القراءة من `data.stats`
        // تُرجع null وتنجح كذبًا (لأن (float) null = 0.0).
        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk()->json();
        $this->assertArrayHasKey('stats', $cash);
        $this->assertSame(0.0, (float) $cash['stats']['balance_now']);
        $this->assertCount(0, $cash['data'], 'البيع الآجل لا يُنشئ حركة صندوق');
    }

    /** الإلغاء يعيد المخزون ويعكس الصندوق، والتقارير تستثني الملغى. */
    public function test_cancel_restores_stock_and_reverses_cash(): void
    {
        $created = $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_id' => $this->medicineId,
                'medicine_name' => 'أموكسيسيلين 500mg',
                'quantity' => 4,
                'unit_price' => 12.50,
            ]],
            'payment_method' => 'cash',
        ])->assertCreated()->json('data');

        $this->assertSame(6, (int) PharmacyMedicine::find($this->pmId)->quantity);

        $this->postJson('/api/pharmacy/accounting/sales/'.$created['number'].'/cancel', [
            'reason' => 'خطأ في الإدخال',
        ])->assertOk();

        // المخزون عاد، والصندوق صفر.
        // الإلغاء لا يحذف الحركة الأصلية بل يسجّل حركة معاكسة
        // (`reverseCashFor` ⇒ SOURCE_ADJUSTMENT) ⇒ حركتان صافيهما صفر.
        $this->assertSame(10, (int) PharmacyMedicine::find($this->pmId)->quantity);

        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk()->json();
        $this->assertArrayHasKey('stats', $cash);
        $this->assertSame(0.0, (float) $cash['stats']['balance_now']);
        $this->assertCount(2, $cash['data'], 'حركة البيع + الحركة المعاكسة');

        // والتقرير يستثني الملغى — لاحظ الشرطة: `sales-summary`.
        $summary = $this->getJson('/api/pharmacy/accounting/sales-summary')
            ->assertOk()
            ->json('data.summary');

        $this->assertSame(0, (int) $summary['count']);
    }

    /** محاولة البيع بمعرّف مخزون صيدلية أخرى تُرفض (IDOR). */
    public function test_selling_foreign_inventory_is_rejected(): void
    {
        $otherUser = User::factory()->pharmacy()->create();
        $otherPharmacy = Pharmacy::factory()->create(['user_id' => $otherUser->id]);
        $otherMedicine = Medicine::factory()->create();

        $foreign = PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $otherMedicine->id,
            'price' => 5.0,
            'quantity' => 50,
            'is_available' => true,
        ]);

        // الرفض بجسم مخصّص (422) وليس ValidationException ⇒ لا `errors.items`.
        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $foreign->id,
                'medicine_name' => 'دواء الغير',
                'quantity' => 1,
                'unit_price' => 5.0,
            ]],
            'payment_method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.invalid_pharmacy_medicine_ids', [(int) $foreign->id]);

        // ولم يُخصم شيء من مخزون الغير
        $this->assertSame(50, (int) PharmacyMedicine::find($foreign->id)->quantity);
    }
}
