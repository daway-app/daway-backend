<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Order;
use App\Models\SubOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Phase 2 — safe patient order cancellation.
 *
 * Dawak has no cash-on-delivery flow and no order-level refund workflow, so a
 * paid order cannot be cancelled without an explicit financial resolution.
 */
class OrderCancellationPolicyTest extends TestCase
{
    use BuildsDeliveryOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    public function test_unpaid_order_can_be_cancelled_before_pickup_with_stock_restored(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);

        $before = $this->pmA->quantity; // 100, deducted by 2 at checkout
        $this->assertSame($before - 2, $this->pmA->fresh()->quantity);

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame($before, $this->pmA->fresh()->quantity, 'stock restored exactly once');
    }

    public function test_paid_order_cancellation_is_blocked_with_refund_required(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->markOrderPaid($order);

        $beforeStatus = $order->fresh()->status;
        $beforeA = $this->pmA->fresh()->quantity; // already deducted to 98 at checkout

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.code', 'refund_required')
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);

        $fresh = $order->fresh();
        $this->assertSame($beforeStatus, $fresh->status, 'status unchanged');
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status, 'payment unchanged');
        $this->assertNull($fresh->rider_id, 'rider assignment untouched');
        $this->assertSame($beforeA, $this->pmA->fresh()->quantity, 'stock untouched');
    }

    public function test_unpaid_order_in_transit_cannot_be_cancelled(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        // Simulate the legacy in-transit-but-unpaid edge (no COD): payment was
        // never captured, yet the order is now in the rider's hands.
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);

        $beforeStatus = $order->fresh()->status;
        $beforeA = $this->pmA->fresh()->quantity;

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'cannot_cancel_in_transit');

        $fresh = $order->fresh();
        $this->assertSame($beforeStatus, $fresh->status, 'status unchanged');
        $this->assertSame((string) $this->rider->id, (string) $fresh->rider_id);
        $this->assertSame(Order::STATUS_PICKED_UP, $fresh->status);
        $this->assertSame($beforeA, $this->pmA->fresh()->quantity, 'stock untouched');
    }

    public function test_delivered_order_cannot_be_cancelled(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertStatus(200);
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(200);

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);

        $beforeA = $this->pmA->fresh()->quantity;

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'cannot_cancel_completed');

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertSame($beforeA, $this->pmA->fresh()->quantity, 'no stock restoration on delivery');
    }

    public function test_repeated_cancellation_does_not_restore_stock_twice(): void
    {
        $order = $this->checkoutTwoPharmacy();

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);
        $this->postJson("/api/patient/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'cannot_cancel_completed');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(100, $this->pmA->fresh()->quantity, 'stock restored exactly once across retries');
    }

    public function test_denied_cancellation_has_no_side_effects(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);

        $historiesBefore = \App\Models\OrderStatusHistory::where('order_id', $order->id)->count();
        $subOrdersBefore = $order->subOrders->mapWithKeys(fn (SubOrder $s) => [$s->id => $s->status])->all();

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $fresh->status);
        $this->assertSame(Order::PAYMENT_PAID, $fresh->payment_status);
        $this->assertDatabaseCount('order_status_histories', $historiesBefore + 0);

        foreach ($fresh->subOrders as $sub) {
            $this->assertSame($subOrdersBefore[$sub->id], $sub->status, 'sub-order status untouched');
        }
    }

    public function test_pharmacy_sub_order_cancellation_remains_allowed_for_unpaid_order(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        $this->assertSame(SubOrder::STATUS_CANCELLED, $subA->fresh()->status);
        $this->assertSame(100, $this->pmA->fresh()->quantity, 'owned pharmacy stock restored once');
        $this->assertSame(100 - 1, $this->pmB->fresh()->quantity, 'other pharmacy untouched');
    }

    public function test_paid_order_pharmacy_sub_order_cancellation_is_blocked_with_refund_required(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $statusBefore = $subA->status;
        $paymentStatusBefore = $order->payment_status;
        $totalBefore = $order->total;
        $deliveryFeeBefore = $order->delivery_fee;
        $stockBefore = $this->pmA->fresh()->quantity;

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.code', 'refund_required')
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);

        $freshSub = $subA->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame($statusBefore, $freshSub->status, 'sub-order status unchanged on denial');
        $this->assertSame($paymentStatusBefore, $freshOrder->payment_status, 'parent payment status unchanged');
        $this->assertSame($totalBefore, $freshOrder->total, 'total field unchanged');
        $this->assertSame($deliveryFeeBefore, $freshOrder->delivery_fee, 'delivery_fee unchanged');
        $this->assertSame($stockBefore, $this->pmA->fresh()->quantity, 'no stock restored on denial');
    }

    public function test_paid_order_legacy_pharmacy_order_cancellation_is_blocked(): void
    {
        // Legacy (pre-Phase-1) order: no sub-orders, so the pharmacy status route
        // takes the inline `updateStatus` branch (NOT `applySubOrderStatus`),
        // which restores stock directly via `restoreOrderInventory`.
        $order = Order::create([
            'user_id' => $this->patient->id,
            'address_id' => $this->address->id,
            'pharmacy_id' => $this->pharmacyA->id,
            'subtotal' => 50,
            'discount' => 0,
            'total' => 50,
            'delivery_fee' => 0,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_PAID,
        ]);
        $order->items()->create([
            'pharmacy_id' => $this->pharmacyA->id,
            'pharmacy_medicine_id' => $this->pmA->id,
            'medicine_trade_name' => 'Legacy Medicine',
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $stockBefore = $this->pmA->fresh()->quantity;

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.code', 'refund_required')
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $fresh->status, 'legacy order status unchanged on denial');
        $this->assertSame($stockBefore, $this->pmA->fresh()->quantity, 'no stock restored on legacy denial');
    }

    public function test_paid_order_pharmacy_preparing_and_ready_still_allowed(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])->assertStatus(200);
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_READY,
        ])->assertStatus(200);

        $this->assertSame(SubOrder::STATUS_READY, $subA->fresh()->status);
    }
}
