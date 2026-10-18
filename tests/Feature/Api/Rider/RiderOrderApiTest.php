<?php

namespace Tests\Feature\Api\Rider;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\SubOrder;
use App\Models\User;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Rider Delivery — واجهة المندوب: العزل، دورة الاستلام/التوصيل/التسليم، والتدقيق.
 */
class RiderOrderApiTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    // ── Authentication ───────────────────────────────────────────────────────

    public function test_rider_login_success(): void
    {
        $resp = $this->postJson('/api/login/rider', [
            'email' => $this->rider->email,
            'password' => 'password',
        ]);

        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data.token'));
        $this->assertSame('rider', $resp->json('data.user.role'));
    }

    public function test_rider_login_wrong_password_is_401(): void
    {
        $this->postJson('/api/login/rider', [
            'email' => $this->rider->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_non_rider_account_cannot_use_rider_login(): void
    {
        $this->postJson('/api/login/rider', [
            'email' => $this->patient->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_inactive_rider_cannot_log_in(): void
    {
        $inactive = User::factory()->rider()->inactive()->create();

        $this->postJson('/api/login/rider', [
            'email' => $inactive->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_patient_token_cannot_access_rider_endpoints(): void
    {
        $this->actingAs($this->patient, 'sanctum');
        $this->getJson('/api/rider/orders')->assertStatus(403);
    }

    // ── Isolation ────────────────────────────────────────────────────────────

    public function test_rider_sees_only_own_assigned_orders(): void
    {
        $mine = $this->readyMultiPharmacyOrder();
        $theirs = $this->readyMultiPharmacyOrder();

        $this->assignAsAdmin($mine, $this->rider);
        $this->assignAsAdmin($theirs, $this->riderTwo);

        $this->actingAs($this->rider, 'sanctum');
        $resp = $this->getJson('/api/rider/orders')->assertStatus(200);

        $this->assertSame(1, $resp->json('pagination.total'));
        $this->assertSame($mine->id, $resp->json('data.0.order_id'));
    }

    public function test_rider_cannot_view_unassigned_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();

        $this->actingAs($this->rider, 'sanctum');
        $this->getJson("/api/rider/orders/{$order->id}")->assertStatus(404);
    }

    public function test_rider_cannot_view_other_riders_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->riderTwo);

        $this->actingAs($this->rider, 'sanctum');
        $this->getJson("/api/rider/orders/{$order->id}")->assertStatus(404);
    }

    public function test_rider_cannot_pickup_other_riders_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->riderTwo);
        $sub = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $sub->id,
        ])->assertStatus(403);
    }

    // ── Pickup lifecycle ─────────────────────────────────────────────────────

    public function test_pickup_marks_sub_order_and_parent_waits_for_the_rest(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $subA->id,
        ])->assertStatus(200);

        $this->assertNotNull($subA->fresh()->picked_up_at);
        // B not picked yet → parent stays ready.
        $this->assertSame(Order::STATUS_READY, $order->fresh()->status);
    }

    public function test_parent_becomes_picked_up_only_after_all_active_sub_orders(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->riderPickupAll($order);

        $this->assertSame(Order::STATUS_PICKED_UP, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->picked_up_at);
        $this->assertSame(
            0,
            $order->subOrders()->whereNull('picked_up_at')
                ->where('status', '!=', SubOrder::STATUS_CANCELLED)->count()
        );
    }

    public function test_cannot_pickup_before_ready(): void
    {
        $order = $this->checkoutTwoPharmacy(); // sub-orders are `confirmed`
        $this->assignAsAdmin($order, $this->rider);
        $sub = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $sub->id,
        ])->assertStatus(422);

        $this->assertNull($sub->fresh()->picked_up_at);
    }

    public function test_cannot_pickup_twice(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $sub = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", ['sub_order_id' => $sub->id])->assertStatus(200);
        $this->postJson("/api/rider/orders/{$order->id}/pickup", ['sub_order_id' => $sub->id])->assertStatus(422);
    }

    public function test_cannot_pickup_cancelled_sub_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $subB = $this->subOrderFor($order, $this->pharmacyB);
        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subB->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $subB->id,
        ])->assertStatus(422);
    }

    public function test_parent_picks_up_when_remaining_active_sub_orders_picked(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        // Cancel B; picking up A must now be enough to make the parent picked_up.
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);
        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subB->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", ['sub_order_id' => $subA->id])->assertStatus(200);

        $this->assertSame(Order::STATUS_PICKED_UP, $order->fresh()->status);
    }

    // ── Delivery lifecycle ───────────────────────────────────────────────────

    public function test_cannot_start_delivery_before_pickup(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => 'out_for_delivery',
        ])->assertStatus(422);

        $this->assertSame(Order::STATUS_READY, $order->fresh()->status);
    }

    public function test_cannot_deliver_before_pickup(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => 'delivered',
        ])->assertStatus(422);

        $this->assertSame(Order::STATUS_READY, $order->fresh()->status);
    }

    public function test_start_delivery_after_full_pickup(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => 'out_for_delivery',
        ])->assertStatus(200);

        $this->assertSame(Order::STATUS_OUT_FOR_DELIVERY, $order->fresh()->status);
    }

    public function test_deliver_marks_parent_and_active_sub_orders_delivered(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertStatus(200);
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_DELIVERED, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame(
            0,
            $fresh->subOrders()->where('status', '!=', SubOrder::STATUS_DELIVERED)->count(),
            'all active sub-orders must be delivered'
        );
    }

    public function test_parent_status_frozen_after_pickup_even_if_pharmacy_cancels(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);
        $this->assertSame(Order::STATUS_PICKED_UP, $order->fresh()->status);

        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        // Derivation must not pull the parent back from the rider-owned phase.
        $this->assertSame(Order::STATUS_PICKED_UP, $order->fresh()->status);
    }

    public function test_pharmacy_cannot_push_delivery_when_rider_assigned(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_OUT_FOR_DELIVERY,
        ])->assertStatus(422);

        $this->assertSame(SubOrder::STATUS_READY, $subA->fresh()->status);
    }

    // ── Audit trail ──────────────────────────────────────────────────────────

    public function test_status_history_records_actor(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $pickedUp = OrderStatusHistory::where('order_id', $order->id)
            ->whereNull('sub_order_id')
            ->where('status', Order::STATUS_PICKED_UP)
            ->firstOrFail();

        $this->assertSame($this->rider->id, (int) $pickedUp->actor_user_id);
        $this->assertSame('rider', $pickedUp->actor_role);

        // The per-pharmacy pickup row also carries the rider.
        $subHistory = OrderStatusHistory::where('order_id', $order->id)
            ->whereNotNull('sub_order_id')
            ->where('status', Order::STATUS_PICKED_UP)
            ->firstOrFail();
        $this->assertSame($this->rider->id, (int) $subHistory->actor_user_id);
        $this->assertSame('rider', $subHistory->actor_role);
    }

    // ── Money safety ─────────────────────────────────────────────────────────

    public function test_delivery_fee_is_unchanged_by_rider_flow(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $feeBefore = (float) $order->delivery_fee;
        $totalBefore = (float) $order->total;

        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertStatus(200);
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(200);

        $fresh = $order->fresh();
        $this->assertSame($feeBefore, (float) $fresh->delivery_fee);
        $this->assertSame($totalBefore, (float) $fresh->total);
    }
}
