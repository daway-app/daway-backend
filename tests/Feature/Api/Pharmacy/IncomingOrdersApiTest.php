<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Order;
use App\Models\User;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class IncomingOrdersApiTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    public function test_list_and_detail_expose_only_the_owning_pharmacy_slice(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $sub = $this->subOrderFor($order, $this->pharmacyA);
        $other = $this->subOrderFor($order, $this->pharmacyB);
        $sub->items()->update(['medicine_trade_name' => 'Own medicine']);
        $other->items()->update(['medicine_trade_name' => 'Private other medicine']);
        // The parent is authoritative even if an old mirror has drifted.
        $order->update(['payment_status' => Order::PAYMENT_PROBLEM_REPORTED]);
        $this->actingAs($this->pharmacyA->user, 'sanctum');

        $list = $this->getJson('/api/pharmacy/sub-orders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $sub->id)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.patient.name', $this->patient->name)
            ->assertJsonPath('data.0.payment_status', Order::PAYMENT_PROBLEM_REPORTED)
            ->assertJsonPath('data.0.fulfillment_method', 'delivery')
            ->assertJsonPath('data.0.currency', 'ILS')
            ->assertJsonPath('data.0.subtotal', 100)
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonPath('data.0.items.0.medicine_trade_name', 'Own medicine')
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.items.0.unit_price', 50)
            ->assertJsonPath('data.0.items.0.total_price', 100)
            ->assertDontSee('Private other medicine');

        $detail = $this->getJson("/api/pharmacy/sub-orders/{$sub->id}")
            ->assertOk()->assertDontSee('Private other medicine');
        $this->assertSame($list->json('data.0'), $detail->json('data'));
        $this->assertArrayNotHasKey('order', $detail->json('data'));
        $this->assertArrayNotHasKey('phone', $detail->json('data.patient'));
        $this->getJson("/api/pharmacy/sub-orders/{$other->id}")->assertNotFound();
        $this->getJson('/api/pharmacy/sub-orders/999999')->assertNotFound();
    }

    public function test_incoming_orders_require_a_pharmacy_token(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $sub = $this->subOrderFor($order, $this->pharmacyA);
        foreach ([$this->patient, $this->admin, $this->rider] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->getJson('/api/pharmacy/sub-orders')->assertForbidden();
            $this->getJson("/api/pharmacy/sub-orders/{$sub->id}")->assertForbidden();
        }
        $this->actingAs(User::factory()->pharmacy()->create(), 'sanctum');
        $this->getJson('/api/pharmacy/sub-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/pharmacy/sub-orders/{$sub->id}")->assertNotFound();
    }
}
