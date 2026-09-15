<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * المحاسبة عبر الـAPI — من الطلب إلى قاعدة البيانات.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * هذه الاختبارات لا تُثبت «أن الـendpoint يرد 200» فقط — تُثبت أن **الآثار
 * الجانبية الحقيقية** تحدث: خصم المخزون، كتابة حركة الصندوق، تحديث دين العميل.
 * لأن هذه هي الأخطاء التي لا يظهرها اختبار الردود.
 *
 * الرقم الأهم في هذا الملف:
 *   `test_sale_deducts_stock_and_writes_cash_movement` — لأن الفاتورة تنجح
 *   بنجاح خصم المخزون، ولو انفصل أحدهما لظهر مخزون وهمي بلا أي خطأ.
 * ══════════════════════════════════════════════════════════════════════════
 */
class AccountingApiTest extends TestCase
{
    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /**
     * سطر مخزون جاهز للبيع.
     *
     * @return array{0:PharmacyMedicine,1:Medicine}
     */
    private function stock(Pharmacy $pharmacy, float $price = 25.00, int $qty = 50): array
    {
        $medicine = Medicine::factory()->create(['trade_name' => 'دواء اختبار']);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'quantity' => $qty,
            'is_available' => true,
        ]);

        return [$pm, $medicine];
    }

    // ══════════════════════════════════════════════════════════════════
    // الصلاحيات
    // ══════════════════════════════════════════════════════════════════

    public function test_guest_cannot_access_accounting(): void
    {
        $this->getJson('/api/pharmacy/accounting/overview')->assertUnauthorized();
        $this->getJson('/api/pharmacy/accounting/sales')->assertUnauthorized();
        $this->getJson('/api/pharmacy/accounting/cash')->assertUnauthorized();
    }

    public function test_patient_cannot_access_accounting(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/accounting/overview')->assertForbidden();
        $this->getJson('/api/pharmacy/accounting/sales')->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════
    // إنشاء فاتورة — الأثر الكامل
    // ══════════════════════════════════════════════════════════════════

    public function test_sale_deducts_stock_and_writes_cash_movement(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 25.00, qty: 10);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_id' => $pm->medicine_id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 25.00,
                'quantity' => 3,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 75)
            ->assertJsonPath('data.paid', 75)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.status', Sale::STATUS_PAID)
            ->assertJsonPath('data.items_count', 1);

        // 1) المخزون خُصم فعلاً — 10 − 3 = 7
        $this->assertSame(7, (int) $pm->fresh()->quantity);

        // 2) حركة صندوق دخلت بمبلغ الفاتورة
        $this->assertDatabaseHas('cash_movements', [
            'pharmacy_id' => $pharmacy->id,
            'direction' => 'in',
            'amount' => 75.00,
            'source_type' => 'sale',
        ]);

        // 3) الفاتورة محفوظة
        $this->assertDatabaseHas('sales', [
            'pharmacy_id' => $pharmacy->id,
            'total' => 75.00,
            'status' => Sale::STATUS_PAID,
        ]);
    }

    public function test_sale_fails_when_stock_insufficient_and_writes_nothing(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, qty: 2);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 25.00,
                'quantity' => 5,   // أكثر من المتاح (2)
            ]],
        ]);

        // 422 مع خطأ عربي واضح على حقل items (شكل أخطاء Laravel القياسي).
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // الأهم: **لا شيء** كُتب — لا فاتورة ولا حركة صندوق
        $this->assertSame(2, (int) $pm->fresh()->quantity);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_sale_rejects_inventory_from_another_pharmacy(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [, $otherPharmacy] = $this->pharmacyUser();

        // سطر مخزون يخص الصيدلية **الأخرى**
        [$foreignPm] = $this->stock($otherPharmacy, qty: 100);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $foreignPm->id,
                'medicine_name' => 'دواء مسروق',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ]);

        // IDOR مرفوض: لا خصم من مخزون صيدلية أخرى
        $response->assertStatus(422);
        $this->assertSame(100, (int) $foreignPm->fresh()->quantity);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_credit_sale_creates_customer_debt_and_no_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 100.00, qty: 10);

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'عميل آجل',
            'phone' => '0599000111',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 100.00,
                'quantity' => 2,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total', 200)
            ->assertJsonPath('data.paid', 0)
            ->assertJsonPath('data.remaining', 200)
            ->assertJsonPath('data.status', Sale::STATUS_UNPAID);

        // دين العميل زاد
        $this->assertSame(200.0, (float) $customer->fresh()->current_balance);

        // الآجل: **لا** حركة صندوق (لا نقد دخل)
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_partial_payment_sets_partially_paid_status(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 50.00, qty: 10);

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'عميل جزئي',
            'phone' => '0599000222',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'paid' => 100.00,
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 50.00,
                'quantity' => 4,   // المجموع 200
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total', 200)
            ->assertJsonPath('data.paid', 100)
            ->assertJsonPath('data.remaining', 100)
            ->assertJsonPath('data.status', Sale::STATUS_PARTIALLY_PAID);

        // الدين = المتبقي فقط، لا الإجمالي
        $this->assertSame(100.0, (float) $customer->fresh()->current_balance);
    }

    public function test_invoice_discount_is_applied_to_total_not_subtotal(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 30.00, qty: 10);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'discount' => 15.00,
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 30.00,
                'quantity' => 4,   // المجموع الفرعي 120
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 120)
            ->assertJsonPath('data.discount', 15)
            ->assertJsonPath('data.total', 105);   // 120 − 15
    }

    public function test_sale_requires_at_least_one_item(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [],
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════
    // إلغاء الفاتورة
    // ══════════════════════════════════════════════════════════════════

    public function test_cancel_sale_restores_stock_and_reverses_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 20.00, qty: 10);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 20.00,
                'quantity' => 4,
            ]],
        ])->assertCreated();

        $number = $create->json('data.number');
        $this->assertSame(6, (int) $pm->fresh()->quantity);

        $cancel = $this->postJson("/api/pharmacy/accounting/sales/{$number}/cancel");

        $cancel->assertOk()->assertJsonPath('data.status', Sale::STATUS_CANCELLED);

        // المخزون رجع كاملاً
        $this->assertSame(10, (int) $pm->fresh()->quantity);

        // حركتا صندوق: دخل 80 ثم خرج 80 ⇒ الرصيد صفر
        $this->assertDatabaseHas('cash_movements', [
            'pharmacy_id' => $pharmacy->id,
            'direction' => 'out',
            'amount' => 80.00,
        ]);
    }

    public function test_cancel_sale_twice_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();
        [$pm] = $this->stock($user->pharmacy, qty: 10);

        Sanctum::actingAs($user);

        $number = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء اختبار',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ])->json('data.number');

        $this->postJson("/api/pharmacy/accounting/sales/{$number}/cancel")->assertOk();
        // الإلغاء الثاني يُرفض (409) — لا إرجاع مخزون مرتين
        $this->postJson("/api/pharmacy/accounting/sales/{$number}/cancel")->assertStatus(409);

        $this->assertSame(10, (int) $pm->fresh()->quantity);
    }

    public function test_cannot_cancel_sale_from_another_pharmacy(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();
        [$pmA] = $this->stock($pharmacyA, qty: 10);

        // صيدلية A تُنشئ فاتورة
        Sanctum::actingAs($userA);
        $number = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pmA->id,
                'medicine_name' => 'دواء',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ])->json('data.number');

        // صيدلية B تحاول إلغاءها ⇒ 404 (لا وجود لها في نطاقها)
        Sanctum::actingAs($userB);
        $this->postJson("/api/pharmacy/accounting/sales/{$number}/cancel")->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════
    // القراءة: القائمة · التفاصيل · النظرة العامة
    // ══════════════════════════════════════════════════════════════════

    public function test_sales_index_returns_only_own_pharmacy_sales(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [$userB, $pharmacyB] = $this->pharmacyUser();

        [$pmA] = $this->stock($pharmacyA, qty: 10);
        [$pmB] = $this->stock($pharmacyB, qty: 10);

        Sanctum::actingAs($userA);
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pmA->id,
                'medicine_name' => 'دواء A',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        Sanctum::actingAs($userB);
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pmB->id,
                'medicine_name' => 'دواء B',
                'unit_price' => 20.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        // نعود لمستخدم الصيدلية A قبل قراءة قائمتها.
        Sanctum::actingAs($userA);

        // الصيدلية A ترى فاتورتها فقط.
        // ⚠️ قائمة المبيعات **لا** تُرجع البنود عمدًا (payload أخف للجدول)؛
        // البنود تظهر في endpoint التفاصيل فقط. لذا نتحقق من الإجمالي.
        $list = $this->getJson('/api/pharmacy/accounting/sales')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame(10.0, (float) $list->json('data.0.total'));
    }

    public function test_sales_index_filters_by_status_and_payment_method(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, qty: 100);

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'عميل',
            'phone' => '0599000333',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        // فاتورة نقدية
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'نقدي',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        // فاتورة آجلة
        $this->postJson('/api/pharmacy/accounting/sales', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'آجل',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $cash = $this->getJson('/api/pharmacy/accounting/sales?payment_method=cash')->assertOk();
        $this->assertCount(1, $cash->json('data'));
        $this->assertSame('cash', $cash->json('data.0.method'));

        $unpaid = $this->getJson('/api/pharmacy/accounting/sales?status=unpaid')->assertOk();
        $this->assertCount(1, $unpaid->json('data'));
        $this->assertSame('credit', $unpaid->json('data.0.method'));
    }

    public function test_unknown_filter_values_are_ignored_not_rejected(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        // قيم غير معروفة ⇒ 200 لا 422 (توافق مع عملاء بلا Accept: json)
        $this->getJson('/api/pharmacy/accounting/sales?status=bogus&payment_method=bogus')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_sale_show_returns_items(): void
    {
        [$user] = $this->pharmacyUser();
        [$pm] = $this->stock($user->pharmacy, price: 12.50, qty: 10);

        Sanctum::actingAs($user);

        $number = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء تفاصيل',
                'unit_price' => 12.50,
                'quantity' => 2,
            ]],
        ])->json('data.number');

        $show = $this->getJson("/api/pharmacy/accounting/sales/{$number}")->assertOk();
        $this->assertSame('دواء تفاصيل', $show->json('data.items.0.medicine_name'));
        $this->assertSame(12.5, (float) $show->json('data.items.0.unit_price'));
        $this->assertSame(25.0, (float) $show->json('data.items.0.line_total'));
    }

    public function test_overview_returns_all_expected_sections(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 40.00, qty: 10);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 40.00,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $overview = $this->getJson('/api/pharmacy/accounting/overview')->assertOk();

        // كل الأقسام التي تعتمد عليها الواجهة
        $overview->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'kpis',
                    'series' => ['today', '7d', '30d', 'month'],
                    'range',
                    'expense_breakdown',
                    'recent_transactions',
                    'alerts',
                    'profit_indicator',
                    'comparison',
                    'receivables',
                    'barcode_coverage' => ['total', 'with_barcode', 'without_barcode', 'percent', 'by_status'],
                ],
            ]);

        // KPI مبيعات اليوم = 80 (فاتورة واحدة فقط)
        $kpis = collect($overview->json('data.kpis'))->keyBy('key');
        $this->assertSame(80.0, (float) $kpis['today_sales']['value']);
        $this->assertSame(80.0, (float) $kpis['cash_balance']['value']);
    }

    public function test_overview_series_today_reflects_sales(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 10.00, qty: 100);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 10.00,
                'quantity' => 5,   // 50
            ]],
        ])->assertCreated();

        $overview = $this->getJson('/api/pharmacy/accounting/overview')->assertOk();

        // سلسلة «اليوم» يجب أن تحمل الـ50 في أحد مقاطعها (لا كلها أصفار)
        $todayData = $overview->json('data.series.today.data');
        $this->assertSame(50.0, round(array_sum($todayData), 2));
    }

    // ══════════════════════════════════════════════════════════════════
    // المصروفات
    // ══════════════════════════════════════════════════════════════════

    public function test_expense_categories_are_created_lazily(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $first = $this->getJson('/api/pharmacy/accounting/expense-categories')->assertOk();
        $this->assertGreaterThan(0, count($first->json('data')));

        // التشغيل الثاني لا يضاعف (idempotent)
        $second = $this->getJson('/api/pharmacy/accounting/expense-categories')->assertOk();
        $this->assertSame(count($first->json('data')), count($second->json('data')));

        $this->assertDatabaseHas('expense_categories', [
            'pharmacy_id' => $pharmacy->id,
            'key' => 'salaries',
        ]);
    }

    public function test_cash_expense_reduces_cash_balance(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 100.00, qty: 10);

        Sanctum::actingAs($user);

        // ادخل 100 نقدًا
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 100.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        // اصرف 30 نقدًا
        $this->postJson('/api/pharmacy/accounting/expenses', [
            'category_key' => 'transport',
            'amount' => 30.00,
            'payment_method' => 'cash',
            'description' => 'مواصلات',
        ])->assertCreated();

        // الرصيد = 70
        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk();
        $this->assertSame(70.0, (float) $cash->json('stats.balance_now'));
    }

    public function test_bank_transfer_expense_does_not_touch_cash(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/expenses', [
            'category_key' => 'rent',
            'amount' => 1800.00,
            'payment_method' => 'bank_transfer',
            'description' => 'إيجار',
        ])->assertCreated();

        // تحويل بنكي ≠ نقد ⇒ الصندوق لم يتغيّر
        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk();
        $this->assertSame(0.0, (float) $cash->json('stats.balance_now'));
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_expense_requires_positive_amount(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/expenses', [
            'category_key' => 'other',
            'amount' => 0,
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_cancel_expense_returns_money_to_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 100.00, qty: 10);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 100.00,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $expenseId = $this->postJson('/api/pharmacy/accounting/expenses', [
            'category_key' => 'transport',
            'amount' => 40.00,
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->assertSame(60.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));

        $this->postJson("/api/pharmacy/accounting/expenses/{$expenseId}/cancel")->assertOk();

        // رجع 100
        $this->assertSame(100.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));
    }

    // ══════════════════════════════════════════════════════════════════
    // العملاء والموردون
    // ══════════════════════════════════════════════════════════════════

    public function test_create_customer_and_duplicate_phone_returns_existing(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'عميل جديد',
            'phone' => '0599123456',
        ])->assertCreated();

        $id = $first->json('data.id');

        // نفس الرقم ⇒ نفس العميل بلا تكرار (لا 409)
        $second = $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'اسم مختلف',
            'phone' => '0599123456',
        ])->assertOk();

        $this->assertSame($id, $second->json('data.id'));
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_same_phone_allowed_across_different_pharmacies(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [$userB, $pharmacyB] = $this->pharmacyUser();

        Sanctum::actingAs($userA);
        $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'عميل A',
            'phone' => '0599999999',
        ])->assertCreated();

        Sanctum::actingAs($userB);
        $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'عميل B',
            'phone' => '0599999999',
        ])->assertCreated();

        // فرادة الهاتف داخل الصيدلية فقط — لا عالميًا
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_customer_payment_reduces_debt_and_increases_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 100.00, qty: 10);

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'مدين',
            'phone' => '0599700000',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        // بيع آجل 200 (لا نقد)
        $this->postJson('/api/pharmacy/accounting/sales', [
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 100.00,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame(200.0, (float) $customer->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));

        // يقبض 120 نقدًا
        $this->postJson("/api/pharmacy/accounting/customers/{$customer->id}/payments", [
            'amount' => 120.00,
            'payment_method' => 'cash',
        ])->assertCreated();

        // الدين نقص، والصندوق زاد
        $this->assertSame(80.0, (float) $customer->fresh()->current_balance);
        $this->assertSame(120.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));
    }

    public function test_customer_payment_rejects_negative_amount(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'عميل',
            'phone' => '0599111000',
            'current_balance' => 100,
            'is_active' => true,
        ]);

        $this->postJson("/api/pharmacy/accounting/customers/{$customer->id}/payments", [
            'amount' => -50.00,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        // الرصيد لم يتغيّر
        $this->assertSame(100.0, (float) $customer->fresh()->current_balance);
    }

    public function test_cannot_pay_customer_from_another_pharmacy(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();

        $customerA = Customer::create([
            'pharmacy_id' => $pharmacyA->id,
            'name' => 'عميل A',
            'phone' => '0599222000',
            'current_balance' => 100,
            'is_active' => true,
        ]);

        Sanctum::actingAs($userB);

        // 404 — العميل خارج نطاق الصيدلية B
        $this->postJson("/api/pharmacy/accounting/customers/{$customerA->id}/payments", [
            'amount' => 50.00,
            'payment_method' => 'cash',
        ])->assertNotFound();

        $this->assertSame(100.0, (float) $customerA->fresh()->current_balance);
    }

    public function test_supplier_payment_reduces_balance_and_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, price: 100.00, qty: 10);

        $supplier = Supplier::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => 'مورّد',
            'phone' => '022950000',
            'current_balance' => 300,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        // نقد في الصندوق أولاً
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء',
                'unit_price' => 100.00,
                'quantity' => 5,
            ]],
        ])->assertCreated();

        $this->postJson("/api/pharmacy/accounting/suppliers/{$supplier->id}/payments", [
            'amount' => 200.00,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame(100.0, (float) $supplier->fresh()->current_balance);
        $this->assertSame(300.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));
    }

    // ══════════════════════════════════════════════════════════════════
    // الصندوق
    // ══════════════════════════════════════════════════════════════════

    public function test_cash_adjustment_requires_reason(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'out',
            'kind' => 'withdrawal',
            'amount' => 100.00,
            // بلا reason
        ])->assertStatus(422);
    }

    public function test_withdrawal_and_deposit_affect_balance_correctly(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'in',
            'kind' => 'deposit',
            'amount' => 500.00,
            'reason' => 'إيداع رأس مال',
        ])->assertCreated();

        $this->assertSame(500.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));

        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'out',
            'kind' => 'withdrawal',
            'amount' => 150.00,
            'reason' => 'سحب نقدي',
        ])->assertCreated();

        $this->assertSame(350.0, (float) $this->getJson('/api/pharmacy/accounting/cash')->json('stats.balance_now'));
    }

    public function test_mismatched_direction_and_kind_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        // withdrawal مع direction=in ⇒ تناقض
        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'in',
            'kind' => 'withdrawal',
            'amount' => 100.00,
            'reason' => 'سبب',
        ])->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════
    // تقارير
    // ══════════════════════════════════════════════════════════════════

    public function test_sales_summary_excludes_cancelled_invoices(): void
    {
        [$user] = $this->pharmacyUser();
        [$pm] = $this->stock($user->pharmacy, price: 50.00, qty: 10);

        Sanctum::actingAs($user);

        // فاتورة تبقى
        $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'تبقى',
                'unit_price' => 50.00,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        // فاتورة تُلغى
        $number = $this->postJson('/api/pharmacy/accounting/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'تُلغى',
                'unit_price' => 50.00,
                'quantity' => 4,
            ]],
        ])->json('data.number');

        $this->postJson("/api/pharmacy/accounting/sales/{$number}/cancel")->assertOk();

        // الملخّص يعرض 100 فقط (الملغاة 200 مستثناة)
        $summary = $this->getJson('/api/pharmacy/accounting/sales-summary')->assertOk();
        $this->assertSame(100.0, (float) $summary->json('data.summary.total'));
        $this->assertSame(1, (int) $summary->json('data.summary.count'));
    }

    public function test_cash_endpoint_reports_period_flow(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'in',
            'kind' => 'deposit',
            'amount' => 300.00,
            'reason' => 'إيداع',
        ])->assertCreated();

        $this->postJson('/api/pharmacy/accounting/cash/adjustments', [
            'direction' => 'out',
            'kind' => 'withdrawal',
            'amount' => 80.00,
            'reason' => 'سحب',
        ])->assertCreated();

        $cash = $this->getJson('/api/pharmacy/accounting/cash')->assertOk();

        $this->assertSame(300.0, (float) $cash->json('stats.period.in'));
        $this->assertSame(80.0, (float) $cash->json('stats.period.out'));
        $this->assertSame(220.0, (float) $cash->json('stats.period.net'));
    }
}
