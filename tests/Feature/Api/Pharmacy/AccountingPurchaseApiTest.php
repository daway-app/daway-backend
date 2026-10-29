<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\CashMovement;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * دورة المشتريات عبر الـAPI.
 *
 * الملف يثبت **الآثار الجانبية** لا أكواد الردود: زيادة المخزون، خروج النقد،
 * صعود رصيد المورد، وعكسها كلها عند الإلغاء. وأهم اختبارين:
 *   - `test_cancel_reverses_stock_cash_and_supplier_balance`
 *   - `test_purchase_cannot_use_another_pharmacy_stock` (العزل)
 */
final class AccountingPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /** @return array{0: PharmacyMedicine, 1: Medicine} */
    private function stock(Pharmacy $pharmacy, int $qty = 0, float $price = 30.00): array
    {
        $medicine = Medicine::factory()->create(['trade_name' => 'دواء شراء']);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $price,
            'quantity' => $qty,
            'is_available' => true,
        ]);

        return [$pm, $medicine];
    }

    private function supplier(Pharmacy $pharmacy, string $name = 'مورد اختبار'): Supplier
    {
        return Supplier::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => $name,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function payload(PharmacyMedicine $pm, array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_id' => $pm->medicine_id,
                'medicine_name' => 'دواء شراء',
                'unit_cost' => 12.50,
                'quantity' => 10,
                'line_discount' => 0,
            ]],
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_purchase_increases_stock_writes_cash_and_raises_supplier_balance(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, qty: 5);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            // بلا paid صريح ⇒ نقدًا يُدفع الإجمالي
        ]))->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subtotal', 125)
            ->assertJsonPath('data.total', 125)
            ->assertJsonPath('data.paid', 125)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.items_count', 1);

        $number = $response->json('data.number');
        $this->assertStringStartsWith('PUR-', $number);

        // المخزون زاد 5 → 15
        $this->assertSame(15, (int) $pm->fresh()->quantity);

        // الصندوق: خروج 125
        $movement = CashMovement::where('pharmacy_id', $pharmacy->id)
            ->where('source_type', CashMovement::SOURCE_PURCHASE)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertEquals(125.0, (float) $movement->amount);

        // المورد مدفوع بالكامل ⇒ لا رصيد
        $this->assertEquals(0.0, (float) $supplier->fresh()->current_balance);

        // أسطر الفاتورة محفوظة
        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $response->json('data.id'),
            'pharmacy_medicine_id' => $pm->id,
            'quantity' => 10,
        ]);
    }

    public function test_credit_purchase_raises_supplier_balance_without_cash_movement(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'credit',
        ]))->assertStatus(201)
            ->assertJsonPath('data.paid', 0)
            ->assertJsonPath('data.remaining', 125)
            ->assertJsonPath('data.status', 'unpaid');

        $this->assertEquals(125.0, (float) $supplier->fresh()->current_balance);

        $this->assertSame(
            0,
            CashMovement::where('pharmacy_id', $pharmacy->id)
                ->where('source_type', CashMovement::SOURCE_PURCHASE)
                ->count()
        );
    }

    public function test_partial_payment_derives_partially_paid_status(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
            'paid' => 50,
        ]))->assertStatus(201)
            ->assertJsonPath('data.paid', 50)
            ->assertJsonPath('data.remaining', 75)
            ->assertJsonPath('data.status', 'partially_paid');

        $this->assertEquals(75.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_invoice_discount_and_line_discount_are_applied(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/purchases', [
            'items' => [[
                'pharmacy_medicine_id' => $pm->id,
                'medicine_name' => 'دواء شراء',
                'unit_cost' => 10,
                'quantity' => 3,
                'line_discount' => 5,
            ]],
            'discount' => 5,
            'payment_method' => 'credit',
        ])->assertStatus(201)
            // 10*3 - 5 = 25 ; 25 - 5 = 20
            ->assertJsonPath('data.subtotal', 25)
            ->assertJsonPath('data.total', 20);
    }

    public function test_cancel_reverses_stock_cash_and_supplier_balance(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, qty: 5);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'cash',
        ]))->assertStatus(201);

        $number = $create->json('data.number');
        $this->assertSame(15, (int) $pm->fresh()->quantity);

        $this->postJson("/api/pharmacy/accounting/purchases/{$number}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.is_cancelled', true);

        // المخزون عاد 5
        $this->assertSame(5, (int) $pm->fresh()->quantity);

        // الصندوق: حركة معاكسة (in) بنفس المبلغ
        $reversal = CashMovement::where('pharmacy_id', $pharmacy->id)
            ->where('direction', 'in')
            ->where('source_type', CashMovement::SOURCE_ADJUSTMENT)
            ->first();

        $this->assertNotNull($reversal);
        $this->assertEquals(125.0, (float) $reversal->amount);

        // المورد: كان 0 (مدفوع) ⇒ يبقى 0 بعد عكس متبقٍ = 0
        $this->assertEquals(0.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_cancel_credit_purchase_reduces_supplier_balance(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'credit',
        ]))->assertStatus(201);

        $this->assertEquals(125.0, (float) $supplier->fresh()->current_balance);

        $this->postJson('/api/pharmacy/accounting/purchases/'.$create->json('data.number').'/cancel')
            ->assertOk();

        $this->assertEquals(0.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_cancel_twice_returns_409(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm))
            ->assertStatus(201);

        $number = $create->json('data.number');

        $this->postJson("/api/pharmacy/accounting/purchases/{$number}/cancel")->assertOk();
        $this->postJson("/api/pharmacy/accounting/purchases/{$number}/cancel")->assertStatus(409);
    }

    public function test_cancel_is_refused_when_stock_was_consumed(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, qty: 0);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm))
            ->assertStatus(201);

        $this->assertSame(10, (int) $pm->fresh()->quantity);

        // بيع 8 من المخزون المشترى ⇒ يتبقى 2 < 10 ⇒ الإلغاء يجب أن يُرفض
        $pm->forceFill(['quantity' => 2])->save();

        $this->postJson('/api/pharmacy/accounting/purchases/'.$create->json('data.number').'/cancel')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_purchase_cannot_use_another_pharmacy_stock(): void
    {
        [$userA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();

        [$pmB] = $this->stock($pharmacyB, qty: 1);

        Sanctum::actingAs($userA);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pmB))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // مخزون الصيدلية الأخرى لم يتغيّر
        $this->assertSame(1, (int) $pmB->fresh()->quantity);
    }

    public function test_purchase_with_foreign_supplier_is_rejected(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacyA);
        $foreignSupplier = $this->supplier($pharmacyB, 'مورد صيدلية أخرى');

        Sanctum::actingAs($userA);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $foreignSupplier->id,
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'المورّد لا يتبع هذه الصيدلية');
    }

    public function test_supplier_payment_linked_to_purchase_updates_invoice(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'credit',
        ]))->assertStatus(201);

        $purchaseId = $create->json('data.id');
        $this->assertEquals(125.0, (float) $supplier->fresh()->current_balance);

        $this->postJson("/api/pharmacy/accounting/suppliers/{$supplier->id}/payments", [
            'amount' => 50,
            'payment_method' => 'cash',
            'purchase_id' => $purchaseId,
        ])->assertStatus(201);

        $purchase = Purchase::find($purchaseId);
        $this->assertEquals(50.0, (float) $purchase->paid);
        $this->assertEquals(75.0, (float) $purchase->remaining);
        $this->assertSame('partially_paid', $purchase->status);

        $this->assertEquals(75.0, (float) $supplier->fresh()->current_balance);
    }

    public function test_list_supports_filters_and_pagination(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);
        $supplier = $this->supplier($pharmacy);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'supplier_id' => $supplier->id,
            'payment_method' => 'credit',
        ]))->assertStatus(201);

        $this->getJson('/api/pharmacy/accounting/purchases?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [['id', 'number', 'total', 'status', 'items_count']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
                'stats' => ['total', 'count', 'paid', 'remaining', 'discount'],
            ])
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('stats.count', 1);

        // فلتر الحالة غير المطابقة ⇒ صفر
        $this->getJson('/api/pharmacy/accounting/purchases?status=paid')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        // فلتر المورد
        $this->getJson('/api/pharmacy/accounting/purchases?supplier_id='.$supplier->id)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_show_is_isolated_per_pharmacy(): void
    {
        [$userA] = $this->pharmacyUser();
        [$userB, $pharmacyB] = $this->pharmacyUser();
        [$pmB] = $this->stock($pharmacyB);

        Sanctum::actingAs($userB);
        $create = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pmB))
            ->assertStatus(201);
        $number = $create->json('data.number');

        Sanctum::actingAs($userA);

        $this->getJson("/api/pharmacy/accounting/purchases/{$number}")
            ->assertStatus(404);

        $this->postJson("/api/pharmacy/accounting/purchases/{$number}/cancel")
            ->assertStatus(404);
    }

    public function test_overview_today_purchases_reflects_real_purchases(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pm, [
            'payment_method' => 'cash',
        ]))->assertStatus(201);

        $kpi = collect($this->getJson('/api/pharmacy/accounting/overview?range=today')
            ->assertOk()
            ->json('data.kpis'))
            ->firstWhere('key', 'today_purchases');

        $this->assertNotNull($kpi);
        $this->assertEquals(125.0, (float) $kpi['value']);
    }

    public function test_patient_cannot_access_purchases(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/accounting/purchases')->assertStatus(403);
    }

    /**
     * 🔒 **حارس العقد**: أخطاء الدفتر تُعاد بنفس غلاف الـAPI.
     *
     * `AccountingLedger` يرمي `InvalidArgumentException` لأخطاء منطقية
     * (صنف لا يخص مخزون الصيدلية، مجاميع سالبة...). كان الكنترولر يحوّلها
     * إلى `ValidationException` فيخرج الرد `{message, errors}` **بلا**
     * مفتاح `success` — فيختلف شكل الخطأ حسب طبقة الفشل، والعميل الذي
     * يقرأ `success` أولًا لا يجد ما يتوقّعه.
     */
    public function test_ledger_error_uses_the_standard_api_envelope(): void
    {
        [$userA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();

        [$pmB] = $this->stock($pharmacyB, qty: 1);

        Sanctum::actingAs($userA);

        $response = $this->postJson('/api/pharmacy/accounting/purchases', $this->payload($pmB))
            ->assertStatus(422);

        // الغلاف الموحّد — كل المفاتيح موجودة وبنفس الأنواع.
        $response->assertJsonPath('success', false);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', (string) $response->json('message'));
        $this->assertIsArray($response->json('data.errors.items'));

        // ولا يتسرّب أي تفصيل تقني من الاستثناء.
        $this->assertArrayNotHasKey('trace', (array) $response->json());
        $this->assertArrayNotHasKey('exception', (array) $response->json());
    }
}
