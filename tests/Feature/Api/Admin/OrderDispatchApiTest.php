<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Order;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Rider Delivery — عرض الطلبات وإسناد/فك إسناد المندوب من لوحة الأدمن.
 */
class OrderDispatchApiTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    private function deliverWithRider(Order $order): void
    {
        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'out_for_delivery'])->assertStatus(200);
        $this->postJson("/api/rider/orders/{$order->id}/status", ['status' => 'delivered'])->assertStatus(200);
        $order->refresh();
    }

    public function test_admin_can_list_orders(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->actingAs($this->admin, 'sanctum');

        $resp = $this->getJson('/api/admin/orders')->assertStatus(200);

        $this->assertSame(1, $resp->json('pagination.total'));
        $this->assertSame($order->id, $resp->json('data.0.order_id'));
        $this->assertNull($resp->json('data.0.rider'));
        $this->assertCount(2, $resp->json('data.0.sub_orders'));
    }

    public function test_admin_can_assign_rider_to_ready_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->actingAs($this->admin, 'sanctum');

        $resp = $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])->assertStatus(200);

        $this->assertSame($this->rider->id, $resp->json('data.rider_id'));
        $this->assertFalse($resp->json('data.reassigned'));

        $fresh = $order->fresh();
        $this->assertSame($this->rider->id, $fresh->rider_id);
        $this->assertSame($this->admin->id, $fresh->assigned_by);
        $this->assertNotNull($fresh->assigned_at);
    }

    public function test_cannot_assign_inactive_rider(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $inactive = \App\Models\User::factory()->rider()->inactive()->create();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $inactive->id,
        ])->assertStatus(422);

        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_cannot_assign_non_rider_user(): void
    {
        $order = $this->readyMultiPharmacyOrder();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->patient->id,
        ])->assertStatus(422);

        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_reassign_before_pickup_is_allowed(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->admin, 'sanctum');
        $resp = $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->riderTwo->id,
        ])->assertStatus(200);

        $this->assertTrue($resp->json('data.reassigned'));
        $this->assertSame($this->rider->id, $resp->json('data.previous_rider_id'));
        $this->assertSame($this->riderTwo->id, $order->fresh()->rider_id);
    }

    public function test_cannot_reassign_after_pickup_started(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->riderTwo->id,
        ])->assertStatus(422);

        $this->assertSame($this->rider->id, $order->fresh()->rider_id);
    }

    public function test_unassign_before_pickup_is_allowed(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->admin, 'sanctum');
        $resp = $this->deleteJson("/api/admin/orders/{$order->id}/assign-rider")->assertStatus(200);

        $this->assertSame($this->rider->id, $resp->json('data.previous_rider_id'));
        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_cannot_unassign_after_pickup_started(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);

        $this->actingAs($this->admin, 'sanctum');
        $this->deleteJson("/api/admin/orders/{$order->id}/assign-rider")->assertStatus(422);
        $this->assertNotNull($order->fresh()->rider_id);
    }

    public function test_cannot_assign_completed_order(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);
        $this->deliverWithRider($order);
        $this->assertSame(Order::STATUS_DELIVERED, $order->status);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->riderTwo->id,
        ])->assertStatus(422);
    }

    public function test_filter_unassigned_and_assigned(): void
    {
        $a = $this->readyMultiPharmacyOrder();
        $b = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($a, $this->rider);

        $this->actingAs($this->admin, 'sanctum');

        $assigned = $this->getJson('/api/admin/orders?assignment=assigned')->assertStatus(200);
        $this->assertSame(1, $assigned->json('pagination.total'));
        $this->assertSame($a->id, $assigned->json('data.0.order_id'));

        $unassigned = $this->getJson('/api/admin/orders?assignment=unassigned')->assertStatus(200);
        $this->assertSame(1, $unassigned->json('pagination.total'));
        $this->assertSame($b->id, $unassigned->json('data.0.order_id'));
    }

    public function test_non_admin_cannot_access_dispatch(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->actingAs($this->rider, 'sanctum');

        $this->getJson('/api/admin/orders')->assertStatus(403);
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])->assertStatus(403);
    }
}
