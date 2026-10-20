<?php

namespace Tests\Feature\Api\Concerns;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SubOrder;
use App\Models\User;

/**
 * Rider Delivery — بناء عالم اختبار مشترك: صيدليتان متقاربتان، مريض، أدمن،
 * ومندوبان، مع طلب متعدد الصيدليات يمكن دفعه إلى `ready` ثم إسناده.
 */
trait BuildsDeliveryOrders
{
    protected Pharmacy $pharmacyA;
    protected Pharmacy $pharmacyB;
    protected PharmacyMedicine $pmA;
    protected PharmacyMedicine $pmB;
    protected User $patient;
    protected User $admin;
    protected User $rider;
    protected User $riderTwo;
    protected Address $address;

    protected function bootDeliveryWorld(): void
    {
        // إحداثيات قريبة (≈167 م) لأن checkout يفرض حد تقارب 500 م.
        $this->pharmacyA = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5000,
            'longitude' => 34.4700,
        ]);
        $this->pharmacyB = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5015,
            'longitude' => 34.4700,
        ]);

        $this->pmA = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacyA->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 50.00,
        ]);
        $this->pmB = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacyB->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 30.00,
        ]);

        $this->patient = User::factory()->patient()->create();
        $this->admin = User::factory()->admin()->create();
        $this->rider = User::factory()->rider()->create();
        $this->riderTwo = User::factory()->rider()->create();

        $this->address = Address::factory()->for($this->patient)->create([
            'recipient_name' => 'عبود',
            'phone' => '059xxxxxxx',
            'address' => 'غزة، فلسطين',
            'latitude' => 31.5016,
            'longitude' => 34.4676,
        ]);

        $this->enableZeroFeeDeliveryPricing();

        $this->actingAs($this->patient, 'sanctum');
    }

    protected function seedTwoPharmacyCart(): void
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $this->patient->id],
            ['items_count' => 0, 'subtotal' => 0]
        );
        $cart->items()->delete();

        foreach ([['pm' => $this->pmA, 'q' => 2], ['pm' => $this->pmB, 'q' => 1]] as $line) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'pharmacy_id' => $line['pm']->pharmacy_id,
                'pharmacy_medicine_id' => $line['pm']->id,
                'quantity' => $line['q'],
                'price' => (float) $line['pm']->price,
            ]);
        }
    }

    protected function checkoutTwoPharmacy(): Order
    {
        $this->actingAs($this->patient, 'sanctum');
        $this->seedTwoPharmacyCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'delivery-'.uniqid(),
        ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    protected function subOrderFor(Order $order, Pharmacy $pharmacy): SubOrder
    {
        return $order->subOrders()->where('pharmacy_id', $pharmacy->id)->firstOrFail();
    }

    /** Push one sub-order to `ready` as its owning pharmacy. */
    protected function advanceSubOrderToReady(SubOrder $subOrder, User $pharmacyUser): void
    {
        $this->actingAs($pharmacyUser, 'sanctum');
        foreach ([SubOrder::STATUS_PREPARING, SubOrder::STATUS_READY] as $status) {
            $this->postJson("/api/pharmacy/sub-orders/{$subOrder->id}/status", [
                'status' => $status,
            ])->assertStatus(200);
        }
    }

    /** A multi-pharmacy order whose parent has derived to `ready`. */
    protected function readyMultiPharmacyOrder(): Order
    {
        $order = $this->checkoutTwoPharmacy();

        $this->advanceSubOrderToReady($this->subOrderFor($order, $this->pharmacyA), $this->pharmacyA->user);
        $this->advanceSubOrderToReady($this->subOrderFor($order, $this->pharmacyB), $this->pharmacyB->user);

        $order->refresh();
        $this->assertSame(Order::STATUS_READY, $order->status);

        return $order;
    }

    /** Assign a rider to an order as the admin. */
    protected function assignAsAdmin(Order $order, User $rider): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/orders/{$order->id}/assign-rider", [
            'rider_id' => $rider->id,
        ])->assertStatus(200);
        $order->refresh();
    }

    /** Rider picks up every active sub-order of the order. */
    protected function riderPickupAll(Order $order): void
    {
        $this->actingAs($this->rider, 'sanctum');
        foreach ($order->subOrders()->get() as $sub) {
            if ($sub->status === SubOrder::STATUS_CANCELLED) {
                continue;
            }
            $this->postJson("/api/rider/orders/{$order->id}/pickup", [
                'sub_order_id' => $sub->id,
            ])->assertStatus(200);
        }
        $order->refresh();
    }
}
