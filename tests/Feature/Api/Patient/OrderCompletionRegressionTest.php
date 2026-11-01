<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Order;
use App\Models\SubOrder;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class OrderCompletionRegressionTest extends TestCase
{
    use BuildsDeliveryOrders;

    public function test_parent_delivery_time_is_set_only_after_all_active_pharmacies_deliver(): void
    {
        $this->bootDeliveryWorld();
        $order = $this->readyMultiPharmacyOrder();

        foreach ([$this->pharmacyA, $this->pharmacyB] as $index => $pharmacy) {
            $sub = $this->subOrderFor($order, $pharmacy);
            $this->actingAs($pharmacy->user, 'sanctum');
            foreach ([SubOrder::STATUS_OUT_FOR_DELIVERY, SubOrder::STATUS_DELIVERED] as $status) {
                $this->postJson("/api/pharmacy/sub-orders/{$sub->id}/status", ['status' => $status])->assertOk();
            }
            $order->refresh();
            if ($index === 0) {
                $this->assertNotSame(Order::STATUS_DELIVERED, $order->status);
                $this->assertNull($order->delivered_at);
            }
        }

        $this->assertSame(Order::STATUS_DELIVERED, $order->status);
        $this->assertNotNull($order->delivered_at);
        $original = $order->delivered_at->toDateTimeString();
        $this->travel(5)->minutes();
        $order->syncStatusFromSubOrders();
        $this->assertSame($original, $order->fresh()->delivered_at->toDateTimeString());
        $this->assertSame(1, $order->statusHistories()->where('status', Order::STATUS_DELIVERED)->count());
    }
}
