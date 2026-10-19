<?php

namespace Tests\Feature\Api;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\AccountingReports;
use Tests\TestCase;

/**
 * Accounting effects of a refund across the four ledgers that matter:
 * the invoice (`paid`/`remaining`/`refunded_amount`/`status`), the customer
 * balance, the cash drawer, and stock — plus the collection path (D6) and the
 * reports (D7).
 *
 * ══════════════════════════════════════════════════════════════════════════
 * The rules this suite pins (decisions D1–D7)
 * ══════════════════════════════════════════════════════════════════════════
 *  - A refund returns GOODS (`amount`), not necessarily MONEY. The goods value
 *    is split: the still-unpaid part cancels debt, only the rest is refunded.
 *      debt_cancelled = min(R, remaining);  cash_refunded = R − debt_cancelled
 *  - Invoice: net_total = total − refunded_amount; remaining = net_total − paid;
 *    status = 'refunded' when refunded_amount ≥ total.
 *  - Cash leaves the drawer only for cash sales, and only if the drawer can
 *    cover it (D5).
 *  - A card/bank collection must not inflate the cash drawer (D6).
 */
class RefundAccountingTest extends TestCase
{
    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /**
     * A sale with `$itemCount` lines, each `$qty × $unitPrice` and wired to a
     * real stock row. Optionally a customer whose `current_balance` starts at
     * the invoice `remaining` (exactly what the ledger would have stored).
     *
     * `$seedCash` mirrors a real cash sale: the collected cash is in the drawer.
     */
    private function createSale(
        Pharmacy $pharmacy,
        string $method = Sale::METHOD_CASH,
        ?float $paid = null,
        int $itemCount = 2,
        int $qty = 5,
        float $unitPrice = 100,
        ?Customer $customer = null,
        bool $seedCash = true,
    ): Sale {
        $lineTotal = $qty * $unitPrice;
        $total = $lineTotal * $itemCount;
        $paid ??= ($method === Sale::METHOD_CREDIT ? 0.0 : $total);
        $remaining = round($total - $paid, 2);

        $sale = Sale::create([
            'pharmacy_id' => $pharmacy->id,
            'number' => 'INV-'.random_int(10000, 99999),
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'paid' => $paid,
            'remaining' => $remaining,
            'refunded_amount' => 0,
            'payment_method' => $method,
            'status' => Sale::deriveStatus($total, $paid),
            'items_count' => $itemCount,
            'created_by' => $pharmacy->user_id,
            'sold_at' => now(),
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            $pm = PharmacyMedicine::factory()->for($pharmacy)->create([
                'medicine_id' => Medicine::factory(),
                'quantity' => 100,
                'price' => $unitPrice,
            ]);

            SaleItem::create([
                'sale_id' => $sale->id,
                'pharmacy_medicine_id' => $pm->id,
                'medicine_id' => null,
                'medicine_name' => 'دواء تجريبي '.$i,
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'line_discount' => 0,
                'line_total' => $lineTotal,
            ]);
        }

        if ($customer !== null) {
            $customer->update(['current_balance' => $remaining]);
        }

        if ($seedCash && $method === Sale::METHOD_CASH && $paid > 0) {
            CashMovement::create([
                'pharmacy_id' => $pharmacy->id,
                'direction' => CashMovement::DIRECTION_IN,
                'amount' => $paid,
                'source_type' => CashMovement::SOURCE_SALE,
                'source_id' => $sale->id,
                'created_by' => $pharmacy->user_id,
                'moved_at' => now(),
            ]);
        }

        return $sale->load('items');
    }

    private function refund(Sale $sale, array $lines, ?string $reason = null)
    {
        return $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => $lines,
            ...($reason !== null ? ['reason' => $reason] : []),
        ]);
    }

    private function cashIn(Pharmacy $pharmacy): float
    {
        return (float) CashMovement::forPharmacy($pharmacy->id)
            ->where('direction', CashMovement::DIRECTION_IN)
            ->sum('amount');
    }

    private function cashOut(Pharmacy $pharmacy): float
    {
        return (float) CashMovement::forPharmacy($pharmacy->id)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->sum('amount');
    }

    // ── 1) Partial refund of a fully paid invoice ──────────────────────────

    public function test_cash_partial_refund_returns_cash_and_restores_stock(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy); // 2 × 5 × 100 = 1000, paid 1000
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 2],
        ], 'إرجاع جزئي')->assertCreated();

        $refund = Refund::latest('id')->firstOrFail();
        $this->assertEquals(200.0, (float) $refund->amount);
        $this->assertEquals(200.0, (float) $refund->cash_refunded);
        $this->assertEquals(0.0, (float) $refund->debt_cancelled);

        $this->assertEquals(200.0, $this->cashOut($pharmacy));
        $this->assertEquals(
            102,
            PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity
        );

        $sale->refresh();
        $this->assertEquals(800.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(200.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_PAID, $sale->status);
    }

    // ── 2) Full refund of a fully paid invoice ─────────────────────────────

    public function test_full_cash_refund_closes_the_invoice(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
            ['sale_item_id' => $sale->items[1]->id, 'quantity' => 5],
        ])->assertCreated();

        $this->assertEquals(1000.0, $this->cashOut($pharmacy));
        $sale->refresh();
        $this->assertEquals(0.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(1000.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_REFUNDED, $sale->status);
        $this->assertEquals(0.0, AccountingReports::cashBalance($pharmacy->id));
    }

    // ── 3) Unpaid (credit) goods return — debt, not cash (D3/D4) ───────────

    public function test_unpaid_credit_return_cancels_debt_without_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'عميل آجل', 'current_balance' => 0]);
        $sale = $this->createSale($pharmacy, method: Sale::METHOD_CREDIT, customer: $customer);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 2],
        ], 'إرجاع بضاعة غير مدفوعة')->assertCreated();

        $refund = Refund::latest('id')->firstOrFail();
        $this->assertEquals(200.0, (float) $refund->amount);
        $this->assertEquals(0.0, (float) $refund->cash_refunded);
        $this->assertEquals(200.0, (float) $refund->debt_cancelled);

        // لا نقد خرج إطلاقًا لأن شيئًا لم يُحصَّل بعد.
        $this->assertEquals(0.0, $this->cashOut($pharmacy));
        $this->assertEquals(0.0, $this->cashIn($pharmacy));

        // المخزون رجع، والدين نقص دون أي حركة نقدية.
        $this->assertEquals(102, PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity);
        $this->assertEquals(800.0, (float) $customer->fresh()->current_balance);

        $sale->refresh();
        $this->assertEquals(0.0, (float) $sale->paid);
        $this->assertEquals(800.0, (float) $sale->remaining);
        $this->assertEquals(Sale::STATUS_UNPAID, $sale->status);
    }

    public function test_full_unpaid_credit_return_closes_the_invoice(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'عميل آجل', 'current_balance' => 0]);
        $sale = $this->createSale($pharmacy, method: Sale::METHOD_CREDIT, customer: $customer);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
            ['sale_item_id' => $sale->items[1]->id, 'quantity' => 5],
        ])->assertCreated();

        $sale->refresh();
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(1000.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_REFUNDED, $sale->status);
        $this->assertEquals(0.0, (float) $customer->fresh()->current_balance);
        $this->assertEquals(0.0, $this->cashOut($pharmacy));
    }

    // ── 4) Partially paid: debt first, then cash (split) ───────────────────

    public function test_partially_paid_refund_cancels_debt_first(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'عميل', 'current_balance' => 0]);
        // 2 × 4 × 100 = 800; paid 400, remaining 400 → customer owes 400.
        $sale = $this->createSale($pharmacy, paid: 400.0, itemCount: 2, qty: 4, unitPrice: 100.0, customer: $customer);
        $this->actingAs($user);

        // Return one full line worth 400: exactly covers the unpaid 400.
        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 4],
        ])->assertCreated();

        $refund = Refund::latest('id')->firstOrFail();
        $this->assertEquals(400.0, (float) $refund->amount);
        $this->assertEquals(0.0, (float) $refund->cash_refunded);
        $this->assertEquals(400.0, (float) $refund->debt_cancelled);

        // No cash moved: the refund offset the outstanding debt exactly.
        $this->assertEquals(0.0, $this->cashOut($pharmacy));

        $sale->refresh();
        $this->assertEquals(400.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(400.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_PAID, $sale->status);
        $this->assertEquals(0.0, (float) $customer->fresh()->current_balance);
    }

    public function test_partially_paid_refund_returns_cash_only_above_debt(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        // 800 total; paid 400, remaining 400.
        $sale = $this->createSale($pharmacy, paid: 400.0, itemCount: 2, qty: 4, unitPrice: 100.0);
        $this->actingAs($user);

        // Return 600 worth: 400 cancels debt, 200 is refunded in cash.
        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 4],
            ['sale_item_id' => $sale->items[1]->id, 'quantity' => 2],
        ])->assertCreated();

        $refund = Refund::latest('id')->firstOrFail();
        $this->assertEquals(600.0, (float) $refund->amount);
        $this->assertEquals(200.0, (float) $refund->cash_refunded);
        $this->assertEquals(400.0, (float) $refund->debt_cancelled);
        $this->assertEquals(200.0, $this->cashOut($pharmacy));

        $sale->refresh();
        $this->assertEquals(200.0, (float) $sale->paid);      // 400 − 200 cash returned
        $this->assertEquals(0.0, (float) $sale->remaining);   // 400 debt cancelled
        $this->assertEquals(600.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_PAID, $sale->status);
    }

    // ── 5) Multiple partial refunds up to the cap (D1) ─────────────────────

    public function test_cumulative_partial_refunds_are_allowed_up_to_paid(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy); // total 1000, paid 1000
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
        ])->assertCreated();

        // Second refund must NOT be capped by the old double-subtract.
        $this->refund($sale, [
            ['sale_item_id' => $sale->items[1]->id, 'quantity' => 5],
        ])->assertCreated();

        $sale->refresh();
        $this->assertEquals(0.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(1000.0, (float) $sale->refunded_amount);
        $this->assertEquals(Sale::STATUS_REFUNDED, $sale->status);
        $this->assertEquals(1000.0, $this->cashOut($pharmacy), 'collected cash returned exactly once');
        $this->assertEquals(105, PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity);
        $this->assertEquals(105, PharmacyMedicine::find($sale->items[1]->pharmacy_medicine_id)->quantity);
    }

    public function test_rejects_cumulative_quantity_over_the_refunded_limit(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 3],
        ])->assertCreated();

        // Only 2 units remain on this line (5 − 3): asking for 3 must fail.
        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 3],
        ])->assertStatus(422);

        // Exactly one refund row and stock restored once (3, not 6).
        $this->assertEquals(1, Refund::count());
        $this->assertEquals(103, PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity);
    }

    // ── 6) Drawer protection (D5) ──────────────────────────────────────────

    public function test_rejects_cash_refund_when_drawer_is_insufficient_with_no_side_effects(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        // Cash sale, but the drawer is empty (money never actually recorded).
        $sale = $this->createSale($pharmacy, seedCash: false);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 2],
        ])->assertStatus(422);

        // Nothing moved at all.
        $this->assertEquals(0, Refund::count());
        $this->assertEquals(0.0, $this->cashOut($pharmacy));
        $this->assertEquals(100, PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity);

        $sale->refresh();
        $this->assertEquals(1000.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->refunded_amount);
    }

    public function test_card_refund_moves_no_drawer_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy, method: Sale::METHOD_CARD);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
        ])->assertCreated();

        // Card money never entered the drawer, so returning it writes nothing.
        $this->assertEquals(0.0, $this->cashIn($pharmacy));
        $this->assertEquals(0.0, $this->cashOut($pharmacy));

        $sale->refresh();
        $this->assertEquals(500.0, (float) $sale->paid);
        $this->assertEquals(0.0, (float) $sale->remaining);
        $this->assertEquals(500.0, (float) $sale->refunded_amount);
        // Retained goods (500) fully settled ⇒ paid, not partially_paid.
        $this->assertEquals(Sale::STATUS_PAID, $sale->status);
        $this->assertEquals(105, PharmacyMedicine::find($sale->items[0]->pharmacy_medicine_id)->quantity);
    }

    public function test_sale_with_refunds_cannot_be_cancelled(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 2],
        ])->assertCreated();

        // Cancelling would restore the *whole* stock again and reverse the debt:
        // a double count. It must be rejected, leaving the refund intact.
        $this->postJson(route('api.pharmacy.accounting.sales.cancel', $sale->number))
            ->assertStatus(422);

        $sale->refresh();
        $this->assertFalse($sale->isCancelled());
        $this->assertEquals(200.0, (float) $sale->refunded_amount);
        $this->assertEquals(1, Refund::count());
    }

    // ── 7) Refundable-items endpoint ───────────────────────────────────────

    public function test_refund_items_endpoint_reports_refunded_and_available(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy);
        $this->actingAs($user);

        $this->getJson(route('api.pharmacy.accounting.sales.refund-items', $sale->number))
            ->assertOk()
            ->assertJsonPath('data.already_refunded', 0)
            ->assertJsonPath('data.available_to_refund', 1000)
            ->assertJsonPath('data.refunded_amount', 0)
            ->assertJsonPath('data.net_total', 1000);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
        ])->assertCreated();

        $this->getJson(route('api.pharmacy.accounting.sales.refund-items', $sale->number))
            ->assertOk()
            ->assertJsonPath('data.already_refunded', 500)
            ->assertJsonPath('data.available_to_refund', 500)
            ->assertJsonPath('data.refunded_amount', 500)
            ->assertJsonPath('data.net_total', 500);
    }

    // ── 8) Customer balance changes exactly once (D3) ──────────────────────

    public function test_partially_paid_refund_reduces_customer_balance_exactly_once(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'عميل', 'current_balance' => 0]);
        $sale = $this->createSale($pharmacy, paid: 400.0, itemCount: 2, qty: 4, unitPrice: 100.0, customer: $customer);
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 4],
        ])->assertCreated();

        // Balance reduced by the debt cancelled (400), once — not by the line
        // value AND the refunded amount.
        $this->assertEquals(0.0, (float) $customer->fresh()->current_balance);
    }

    // ── 9) Collection path is tender-aware (D6) ────────────────────────────

    public function test_card_customer_collection_does_not_touch_drawer(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'مدين', 'current_balance' => 0]);
        $sale = $this->createSale($pharmacy, method: Sale::METHOD_CREDIT, customer: $customer); // 1000 owed
        $this->actingAs($user);

        $this->postJson(route('api.pharmacy.accounting.customers.payments', $customer->id), [
            'amount' => 120.0,
            'payment_method' => 'card',
            'sale_id' => $sale->id,
        ])->assertCreated();

        $this->assertEquals(0.0, $this->cashIn($pharmacy), 'card collection must not inflate cash');
        $this->assertEquals(0, CashMovement::forPharmacy($pharmacy->id)->count());
        $this->assertEquals(880.0, (float) $customer->fresh()->current_balance);

        $sale->refresh();
        $this->assertEquals(120.0, (float) $sale->paid);
        $this->assertEquals(880.0, (float) $sale->remaining);
    }

    public function test_cash_customer_collection_increases_drawer(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $customer = Customer::create(['pharmacy_id' => $pharmacy->id, 'name' => 'مدين', 'current_balance' => 0]);
        $sale = $this->createSale($pharmacy, method: Sale::METHOD_CREDIT, customer: $customer);
        $this->actingAs($user);

        $this->postJson(route('api.pharmacy.accounting.customers.payments', $customer->id), [
            'amount' => 120.0,
            'payment_method' => 'cash',
            'sale_id' => $sale->id,
        ])->assertCreated();

        $this->assertEquals(120.0, $this->cashIn($pharmacy), 'cash collection enters the drawer');
        $this->assertEquals(880.0, (float) $customer->fresh()->current_balance);

        $sale->refresh();
        $this->assertEquals(120.0, (float) $sale->paid);
        $this->assertEquals(880.0, (float) $sale->remaining);
    }

    // ── 10) Reports consistency (D7) ───────────────────────────────────────

    public function test_refund_summary_separates_goods_value_from_cash(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy); // cash 1000, paid 1000
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 2],
        ])->assertCreated();

        $summary = $this->getJson('/api/pharmacy/accounting/sales-summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(1000.0, (float) $summary['summary']['total']);
        $this->assertEquals(200.0, (float) $summary['summary']['refunded']);
        $this->assertEquals(800.0, (float) $summary['summary']['net_total']);

        $refunds = $summary['refunds'];
        $this->assertEquals(1000.0, (float) $refunds['gross_sales']);
        $this->assertEquals(200.0, (float) $refunds['refunded_goods']);
        $this->assertEquals(800.0, (float) $refunds['net_sales']);
        $this->assertEquals(200.0, (float) $refunds['cash_refunded']);
        $this->assertEquals(0.0, (float) $refunds['debt_cancelled']);
    }

    public function test_receivables_exclude_cancelled_and_fully_refunded(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSale($pharmacy); // cash, fully paid
        $this->actingAs($user);

        $this->refund($sale, [
            ['sale_item_id' => $sale->items[0]->id, 'quantity' => 5],
            ['sale_item_id' => $sale->items[1]->id, 'quantity' => 5],
        ])->assertCreated();

        // Fully refunded invoice carries no live receivable.
        $audit = AccountingReports::receivablesAudit($pharmacy->id);
        $this->assertEquals(0.0, (float) $audit['invoice_remaining']);
        $this->assertEquals(0.0, (float) $audit['customer_balances']);
    }
}
