<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Phase 0 — Audit trail regression tests.
 *
 * These pin the OBSERVABLE contract of the audit trail for the five
 * state/financially-sensitive models:
 *
 *  1. A lifecycle status change is logged EXACTLY ONCE, with the correct
 *     old/new pair — including cancellation, which used to perform two
 *     consecutive status writes.
 *  2. A Refund activity links to the correct causer when an authenticated
 *     user exists.
 *  3. Fields that were never meant to be audited (patient address snapshots,
 *     free-form line notes) are NOT recorded.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $pharmacy;
    private PharmacyMedicine $pharmacyMedicine;
    private User $patient;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Pharmacy::factory()->create(['is_active' => true]);
        $this->pharmacyMedicine = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 50.00,
        ]);

        $this->patient = User::factory()->patient()->create();
        $this->address = Address::factory()->for($this->patient)->create([
            'recipient_name' => 'عبود',
            'phone' => '059xxxxxxx',
            'address' => 'غزة، فلسطين',
            'latitude' => 31.5016,
            'longitude' => 34.4676,
        ]);

        $this->actingAs($this->patient, 'sanctum');
    }

    private function seedCart(int $quantity = 2): void
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->patient->id], [
            'items_count' => 0,
            'subtotal' => 0,
        ]);
        $cart->items()->delete();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => $quantity,
            'price' => (float) $this->pharmacyMedicine->price,
        ]);
        $cart->refresh();
    }

    private function checkout(): Order
    {
        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'audit-trail-'.uniqid(),
        ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    // ── 1. Exactly-once status logging ───────────────────────────────────

    public function test_cancellation_logs_exactly_one_status_transition(): void
    {
        $this->seedCart(2);
        $order = $this->checkout();

        $before = Activity::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->where('event', 'updated')
            ->count();

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        $rows = Activity::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->where('event', 'updated')
            ->get();

        // The cancellation is ONE transition: one new row, not two.
        $this->assertSame($before + 1, $rows->count());

        $last = $rows->sortByDesc('id')->first();
        $changes = $last->getAttribute('attribute_changes');

        $this->assertSame(Order::STATUS_CANCELLED, $changes['attributes']['status']);
        $this->assertSame(Order::STATUS_CONFIRMED, $changes['old']['status']);
    }

    public function test_sub_order_status_change_is_logged_once_under_its_own_log_name(): void
    {
        $this->seedCart(2);
        $order = $this->checkout();

        $subOrder = SubOrder::where('order_id', $order->id)->firstOrFail();

        $before = Activity::where('subject_type', SubOrder::class)
            ->where('subject_id', $subOrder->id)
            ->where('event', 'updated')
            ->count();

        // The owning pharmacy user is the one created with the pharmacy.
        $this->actingAs($this->pharmacy->user, 'sanctum');

        $this->postJson("/api/pharmacy/sub-orders/{$subOrder->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])->assertStatus(200);

        $rows = Activity::where('subject_type', SubOrder::class)
            ->where('subject_id', $subOrder->id)
            ->where('event', 'updated')
            ->get();

        $this->assertSame($before + 1, $rows->count());

        $last = $rows->sortByDesc('id')->first();
        $this->assertSame('sub_order', $last->log_name);

        $changes = $last->getAttribute('attribute_changes');
        $this->assertSame(SubOrder::STATUS_PREPARING, $changes['attributes']['status']);
        $this->assertSame(SubOrder::STATUS_CONFIRMED, $changes['old']['status']);
    }

    // ── 2. Refund → causer linkage ───────────────────────────────────────

    /**
     * Build a paid sale whose single line points at the seeded medicine, so a
     * refund of one unit is always valid.
     */
    private function seedSale(float $unitPrice = 50.00, int $quantity = 2): array
    {
        $sale = Sale::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'subtotal' => $unitPrice * $quantity,
            'total' => $unitPrice * $quantity,
            'paid' => $unitPrice * $quantity,
            'remaining' => 0.00,
            'created_by' => $this->pharmacy->user_id,
        ]);

        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'medicine_id' => null,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'medicine_name' => 'Panadol 500mg',
            'barcode' => null,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_discount' => 0,
            'line_total' => round($unitPrice * $quantity, 2),
        ]);

        // بيع نقدي ⇒ النقد في الدرج، حتى يمرّ فحص كفاية الصندوق عند الإرجاع.
        CashMovement::create([
            'pharmacy_id' => $this->pharmacy->id,
            'direction' => CashMovement::DIRECTION_IN,
            'amount' => round($unitPrice * $quantity, 2),
            'source_type' => CashMovement::SOURCE_SALE,
            'source_id' => $sale->id,
            'created_by' => $this->pharmacy->user_id,
            'moved_at' => now(),
        ]);

        return [$sale, $saleItem];
    }

    public function test_refund_activity_records_the_authenticated_causer(): void
    {
        [$sale, $saleItem] = $this->seedSale();

        $pharmacist = $this->pharmacy->user;
        $this->actingAs($pharmacist, 'sanctum');

        $this->postJson('/api/pharmacy/accounting/refunds', [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
            'reason' => 'صنف تالف',
        ])->assertStatus(201);

        $refund = Refund::latest('id')->firstOrFail();

        $activity = Activity::where('subject_type', Refund::class)
            ->where('subject_id', $refund->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity, 'Refund creation was not audited.');
        $this->assertSame('refund', $activity->log_name);

        // The causer is the authenticated pharmacist.
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame($pharmacist->id, (int) $activity->causer_id);

        // And the amount/actor survive in the change set itself.
        $changes = $activity->getAttribute('attribute_changes');
        $this->assertEquals(50.0, (float) $changes['attributes']['amount']);
        $this->assertSame($pharmacist->id, (int) $changes['attributes']['created_by']);
    }

    public function test_refund_item_creation_is_audited(): void
    {
        [$sale, $saleItem] = $this->seedSale();

        $this->actingAs($this->pharmacy->user, 'sanctum');
        $this->postJson('/api/pharmacy/accounting/refunds', [
            'sale_number' => $sale->number,
            'items' => [['sale_item_id' => $saleItem->id, 'quantity' => 1]],
            'reason' => 'صنف تالف',
        ])->assertStatus(201);

        $refund = Refund::latest('id')->firstOrFail();
        $refundItem = RefundItem::where('refund_id', $refund->id)->firstOrFail();

        $activity = Activity::where('subject_type', RefundItem::class)
            ->where('subject_id', $refundItem->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity, 'RefundItem creation was not audited.');
        $this->assertSame('refund_item', $activity->log_name);
    }

    // ── 3. Sensitive data must NOT be recorded ───────────────────────────

    public function test_order_activity_does_not_leak_address_snapshot_or_cart_snapshot(): void
    {
        $this->seedCart(2);
        $order = $this->checkout();

        // The order genuinely carries the personal blob — the test is not vacuous.
        $this->assertNotNull($order->address_snapshot);
        $this->assertSame('غزة، فلسطين', $order->address_snapshot['address']);

        $activities = Activity::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->get();

        $this->assertNotEmpty($activities);

        foreach ($activities as $activity) {
            $changes = $activity->getAttribute('attribute_changes') ?? [];

            foreach (['address_snapshot', 'snapshot', 'address'] as $sensitive) {
                $this->assertArrayNotHasKey(
                    $sensitive,
                    $changes['attributes'] ?? [],
                    "Audit row leaked `{$sensitive}`."
                );
                $this->assertArrayNotHasKey(
                    $sensitive,
                    $changes['old'] ?? [],
                    "Audit row leaked `{$sensitive}` in old values."
                );
            }

            // Belt and braces: the raw payload must not contain the phone number
            // or the recipient name either.
            $raw = json_encode($changes, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('059xxxxxxx', $raw);
            $this->assertStringNotContainsString('عبود', $raw);
        }
    }

    public function test_order_line_free_text_is_not_audited(): void
    {
        $this->seedCart(2);
        $order = $this->checkout();

        $item = OrderItem::where('order_id', $order->id)->firstOrFail();

        // `medicine_description` exists on the model but is deliberately not in
        // the log set.
        $item->update(['medicine_description' => 'ملاحظة شخصية للمريض']);

        $rows = Activity::where('subject_type', OrderItem::class)
            ->where('subject_id', $item->id)
            ->get();

        foreach ($rows as $row) {
            $raw = json_encode($row->getAttribute('attribute_changes'), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('ملاحظة شخصية للمريض', $raw);
        }
    }

    public function test_no_activity_row_is_written_when_no_tracked_field_changed(): void
    {
        $this->seedCart(2);
        $order = $this->checkout();

        $before = Activity::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->count();

        // `external_order_id` is a real, writable column that is NOT tracked.
        $order->update(['external_order_id' => 'EXT-NOT-TRACKED']);

        $after = Activity::where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->count();

        $this->assertSame($before, $after, 'An untracked field produced an audit row.');
    }
}
