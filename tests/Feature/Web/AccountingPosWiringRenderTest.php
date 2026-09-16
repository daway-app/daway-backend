<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

/**
 * عقد ربط صفحة نقطة البيع — الإعداد الذي يصل للمتصفح فعلًا.
 *
 * ⚠️ السبب وراء هذا الملف: كانت `sale-create.blade.php` تعرّف `endpoints`
 * **محليًّا**، و`Object.assign` سطحية ⇒ يُستبدل الكائن ويضيع الـ19 مسارًا
 * المحاسبية. النتيجة: `salesCreate` = undefined ⇒ الحفظ يرجع
 * `not_configured` **بلا أي طلب شبكي** — عطل صامت تمامًا.
 *
 * هذه الاختبارات تقرأ HTML المُصيَّر وتتحقق من وجود المسارات فيه.
 */
class AccountingPosWiringRenderTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $this->user->id]);
    }

    /** المسارات الـ19 التي يجب أن تصل للصفحة كاملةً. */
    private const REQUIRED_ENDPOINTS = [
        // النظرة العامة
        'overview',
        // المبيعات — الحفظ والإلغاء هما الأخطر
        'salesIndex', 'salesCreate', 'salesSummary', 'salesShow', 'salesCancel',
        // المصروفات
        'expensesIndex', 'expensesCreate', 'expensesCancel', 'expenseCategories',
        // الأطراف
        'customersIndex', 'customersCreate', 'customersPayment',
        'suppliersIndex', 'suppliersCreate', 'suppliersPayment',
        // الصندوق
        'cashIndex', 'cashAdjust',
        // البحث
        'medicineSearch', 'barcodeLookup',
    ];

    public function test_pos_page_carries_every_accounting_endpoint(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        // نستخرج كائن الإعداد من السكربت
        $this->assertStringContainsString('window.acAccountingConfig', $html);

        foreach (self::REQUIRED_ENDPOINTS as $key) {
            $this->assertStringContainsString(
                $key,
                $html,
                "المسار `{$key}` مفقود من إعداد الصفحة — الواجهة ستفشل بـnot_configured"
            );
        }
    }

    /**
     * مسار حفظ الفاتورة **بقيمة URL حقيقية** لا مجرد اسم المفتاح.
     *
     * ملاحظة: `@json` تُهرّب الشرطات المائلة (`\/`) — لذا نُطبّع قبل البحث
     * بدل أن نفشل على اختلاف ترميز لا معنى له.
     */
    public function test_pos_page_contains_real_sales_create_url(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        $normalized = str_replace('\\/', '/', $html);

        $this->assertStringContainsString('/api/pharmacy/accounting/sales', $normalized);
        $this->assertStringContainsString('/api/pharmacy/accounting/sales-summary', $normalized);
        $this->assertStringContainsString('/api/pharmacy/accounting/overview', $normalized);
    }

    /**
     * ⚠️ الإثبات الحاسم للعطل: كتلة `endpoints` **لا تُستبدل**.
     *
     * كانت `Object.assign` سطحية فتُلغي كتلة الـendpoints القادمة من
     * الـpartial. نتحقق أن أول كائن إعداد يحتوي المسارات المحاسبية مع
     * مسارات البحث — أي أنهما في نفس الكائن لا في كائن دُمِّر.
     */
    public function test_endpoints_block_is_merged_not_replaced(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        // الكتلة الأولى: `window.acAccountingConfig = window.acAccountingConfig || {...}`
        // يجب أن تحوي المسار المحاسبي والبحثي معًا.
        $this->assertMatchesRegularExpression(
            '/"endpoints":\{[^}]*"medicineSearch"[^}]*"salesCreate"/s',
            str_replace('\\/', '/', $html),
            'المسارات المحاسبية والبحثية لازم يكونون في نفس كائن endpoints'
        );
    }

    /** عنوان الفاتورة يُبنى بـroute() لا في JS. */
    public function test_pos_page_exposes_invoice_url_template(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('invoiceUrlTemplate', $html);
        $this->assertStringContainsString('__NUMBER__', $html);
    }

    /** حقل العميل بلا datalist وهمي — البحث حقيقي عبر الـAPI. */
    public function test_customer_field_has_real_results_container(): void
    {
        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ac-customer-matches', $html);
        $this->assertStringNotContainsString('ac-customers-list', $html);
    }

    /** كتالوج الـPOS يحمل `medicine_id` (محور الربط مع الباركود). */
    public function test_pos_catalog_includes_medicine_id_axis(): void
    {
        // ⚠️ الكتالوج فارغ لصيدلية بلا مخزون ⇒ لا يظهر المفتاح إطلاقًا.
        // نزرع صنفًا حقيقيًا حتى نقيس المفتاح فعلًا لا غيابه.
        $pharmacy = Pharmacy::where('user_id', $this->user->id)->firstOrFail();
        $medicine = Medicine::factory()->create(['trade_name' => 'صنف اختبار المحور']);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
            'price' => 5.0,
            'is_available' => true,
        ]);

        $html = $this->actingAs($this->user)
            ->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        // ⚠️ المحوران في الـpayload: `id` = صف المخزون (`pharmacy_medicines.id`،
        // وهو ما يخصمه الـLedger) و`medicine_id` = الدواء العالمي
        // (`medicines.id`، وهو ما تعيده نقطة الباركود كـ`local_medicine_id`).
        // مفتاح `pharmacy_medicine_id` **لا** يُحفَر في الكتالوج — الـJS يبنيه
        // في سطر السلة عند الإضافة. لذا نتحقق من محورَي الـpayload فقط.
        $this->assertStringContainsString('"medicine_id":', $html);
        $this->assertMatchesRegularExpression('/"id":\d+/', $html);
        $this->assertStringContainsString('"trade_name"', $html);
        $this->assertStringContainsString('"quantity":3', $html);
    }
}
