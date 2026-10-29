<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SubOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Phase 1 — no order may enter or continue fulfillment unless the parent
 * order payment_status is `paid`. Dawak has no cash-on-delivery flow.
 */
class FulfillmentPaymentGateTest extends TestCase
{
    use BuildsDeliveryOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    public function test_pending_payment_blocks_pharmacy_fulfillment_without_side_effects(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
        $historyBefore = OrderStatusHistory::where('order_id', $order->id)->count();

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.code', 'payment_required')
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PENDING);

        $this->assertSame(SubOrder::STATUS_CONFIRMED, $subA->fresh()->status);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertSame($historyBefore, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_under_review_and_rejected_payments_are_blocked(): void
    {
        foreach ([Order::PAYMENT_UNDER_REVIEW, Order::PAYMENT_REJECTED] as $paymentStatus) {
            $order = $this->checkoutTwoPharmacy();
            $order->update(['payment_status' => $paymentStatus]);
            $subA = $this->subOrderFor($order, $this->pharmacyA);

            $this->actingAs($this->pharmacyA->user, 'sanctum');
            $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
                'status' => SubOrder::STATUS_PREPARING,
            ])
                ->assertStatus(422)
                ->assertJsonPath('data.code', 'payment_required')
                ->assertJsonPath('data.payment_status', $paymentStatus);

            $this->assertSame(SubOrder::STATUS_CONFIRMED, $subA->fresh()->status);
        }
    }

    public function test_paid_payment_allows_fulfillment(): void
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

    public function test_sub_order_payment_mirror_cannot_bypass_the_parent_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        // Parent is unpaid (legacy/in-flight edge), while the display mirror on
        // the sub-order still says paid. The parent must win.
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);
        $this->assertSame(Order::PAYMENT_PAID, $subA->fresh()->payment_status);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');

        $fresh = $order->fresh();
        $this->assertNull($fresh->rider_id);
        $this->assertNull($fresh->assigned_at);
        $this->assertNull($fresh->assigned_by);
    }

    public function test_unpaid_rider_pickup_and_delivery_are_blocked(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $order->update(['payment_status' => Order::PAYMENT_PENDING]);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $subA->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');

        $this->assertNull($subA->fresh()->picked_up_at);

        // Restore payment, complete pickup, then verify delivery transitions
        // are gated as well.
        $this->markOrderPaid($order);
        $this->riderPickupAll($order);

        $order->update(['payment_status' => Order::PAYMENT_PENDING]);

        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => Order::STATUS_OUT_FOR_DELIVERY,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');

        $this->assertSame(Order::STATUS_PICKED_UP, $order->fresh()->status);
    }

    public function test_legacy_parent_route_is_gated_for_multi_pharmacy_orders(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');

        $this->assertSame(SubOrder::STATUS_CONFIRMED, $subA->fresh()->status);
    }

    public function test_legacy_order_without_sub_orders_is_gated(): void
    {
        $order = Order::create([
            'user_id' => $this->patient->id,
            'address_id' => $this->address->id,
            'pharmacy_id' => $this->pharmacyA->id,
            'subtotal' => 50,
            'discount' => 0,
            'total' => 50,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_PENDING,
        ]);

        $order->items()->create([
            'pharmacy_id' => $this->pharmacyA->id,
            'pharmacy_medicine_id' => $this->pmA->id,
            'medicine_trade_name' => 'Legacy Medicine',
            'quantity' => 1,
            'unit_price' => 50,
            'total_price' => 50,
        ]);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => Order::STATUS_PREPARING,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');

        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);

        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => Order::STATUS_PREPARING,
        ])->assertStatus(200);

        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status);
    }

    public function test_cancellation_remains_allowed_while_unpaid(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        $this->assertSame(SubOrder::STATUS_CANCELLED, $subA->fresh()->status);
    }
}
