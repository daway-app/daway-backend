<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 — Multi-pharmacy orders / sub-orders.
 *
 * A single checkout can span several pharmacies: the parent `orders` row is the
 * header, and each pharmacy gets an independent `sub_orders` row. These tests
 * cover the data design, the atomic checkout, the independent state machines,
 * parent-status derivation, pharmacy isolation, and legacy compatibility.
 */
class MultiPharmacyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $pharmacyA;
    private Pharmacy $pharmacyB;
    private PharmacyMedicine $pmA;
    private PharmacyMedicine $pmB;
    private User $patient;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 2 — الصيدليتان يجب أن تكونا داخل حد التقارب (500 م) الذي
        // يفرضه checkout. إحداثيات الـfactory عشوائية على نطاق عشرات
        // الكيلومترات، فكانت كل اختبارات تعدد الصيدليات ستُرفض بسبب
        // المسافة لا بسبب السلوك تحت الاختبار.
        $this->pharmacyA = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5000,
            'longitude' => 34.4700,
        ]);
        $this->pharmacyB = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5015,   // ≈167 م شمال A
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
        $this->address = Address::factory()->for($this->patient)->create([
            'recipient_name' => 'عبود',
            'phone' => '059xxxxxxx',
            'address' => 'غزة، فلسطين',
            'latitude' => 31.5016,
            'longitude' => 34.4676,
        ]);

        $this->actingAs($this->patient, 'sanctum');
    }

    /**
     * @param  array<int, array{pm: PharmacyMedicine, quantity: int}>  $lines
     */
    private function seedCart(array $lines): Cart
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $this->patient->id],
            ['items_count' => 0, 'subtotal' => 0]
        );
        $cart->items()->delete();

        foreach ($lines as $line) {
            /** @var PharmacyMedicine $pm */
            $pm = $line['pm'];
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'pharmacy_id' => $pm->pharmacy_id,
                'pharmacy_medicine_id' => $pm->id,
                'quantity' => $line['quantity'],
                'price' => (float) $pm->price,
            ]);
        }

        $cart->refresh();

        return $cart;
    }

    /** Two-pharmacy cart: A×2 (100.00) + B×1 (30.00) = 130.00. */
    private function seedTwoPharmacyCart(): Cart
    {
        return $this->seedCart([
            ['pm' => $this->pmA, 'quantity' => 2],
            ['pm' => $this->pmB, 'quantity' => 1],
        ]);
    }

    private function checkout(array $extra = []): array
    {
        $payload = array_merge([
            'address_id' => $this->address->id,
            'idempotency_key' => 'mp-'.uniqid(),
        ], $extra);

        $resp = $this->postJson('/api/patient/checkout', $payload);

        return [$resp->status(), $resp->json()];
    }

    private function subOrderFor(Order $order, Pharmacy $pharmacy): SubOrder
    {
        return $order->subOrders()->where('pharmacy_id', $pharmacy->id)->firstOrFail();
    }

    /** Advance a sub-order through a chain of statuses as the owning pharmacy. */
    private function advance(SubOrder $subOrder, User $pharmacyUser, array $statuses): void
    {
        $this->actingAs($pharmacyUser, 'sanctum');
        foreach ($statuses as $status) {
            $this->postJson("/api/pharmacy/sub-orders/{$subOrder->id}/status", [
                'status' => $status,
            ])->assertStatus(200);
        }
    }

    // ── Stage A/B — data design & atomic checkout ────────────────────────────

    public function test_single_pharmacy_checkout_creates_exactly_one_sub_order(): void
    {
        $this->seedCart([['pm' => $this->pmA, 'quantity' => 2]]);

        [$status] = $this->checkout();
        $this->assertSame(201, $status);

        $order = Order::firstOrFail();
        $this->assertSame($this->pharmacyA->id, $order->pharmacy_id, 'single-pharmacy order keeps pharmacy_id');
        $this->assertSame(1, $order->subOrders()->count());
        $this->assertFalse($order->isMultiPharmacy());

        $sub = $order->subOrders()->first();
        $this->assertSame($this->pharmacyA->id, $sub->pharmacy_id);
        $this->assertEquals(100.0, (float) $sub->subtotal);
        $this->assertEquals(100.0, (float) $sub->total);
        $this->assertSame(SubOrder::STATUS_CONFIRMED, $sub->status);
        $this->assertSame(1, $sub->items()->count());
    }

    public function test_multi_pharmacy_checkout_creates_parent_and_two_sub_orders(): void
    {
        $this->seedTwoPharmacyCart();

        [$status, $body] = $this->checkout();
        $this->assertSame(201, $status);
        $this->assertSame(2, $body['data']['sub_orders_count']);

        $order = Order::firstOrFail();
        $this->assertNull($order->pharmacy_id, 'multi-pharmacy order has no single pharmacy_id');
        $this->assertSame(2, $order->subOrders()->count());
        $this->assertEquals(130.0, (float) $order->subtotal);
        $this->assertEquals(130.0, (float) $order->total);
        $this->assertTrue($order->isMultiPharmacy());

        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->assertEquals(100.0, (float) $subA->subtotal);
        $this->assertEquals(30.0, (float) $subB->subtotal);
        $this->assertEquals((float) $order->total, (float) ($subA->total + $subB->total));
    }

    public function test_items_are_linked_to_their_own_sub_order(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->assertSame(1, $subA->items()->count());
        $this->assertSame(1, $subB->items()->count());
        $this->assertSame($subA->id, $subA->items()->first()->sub_order_id);
        $this->assertSame($subB->id, $subB->items()->first()->sub_order_id);

        // Every item is attached to exactly one sub-order (no orphans).
        $this->assertSame(2, $order->items()->whereNotNull('sub_order_id')->count());
        $this->assertSame(0, $order->items()->whereNull('sub_order_id')->count());
    }

    public function test_sub_order_is_unique_per_order_and_pharmacy(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // A second sub-order for the same pharmacy in the same order must fail.
        $order->subOrders()->create([
            'pharmacy_id' => $this->pharmacyA->id,
            'total' => 0,
        ]);
    }

    public function test_multi_pharmacy_checkout_rolls_back_if_one_pharmacy_is_out_of_stock(): void
    {
        $this->seedCart([
            ['pm' => $this->pmA, 'quantity' => 1],
            ['pm' => $this->pmB, 'quantity' => 5],
        ]);
        $this->pmB->update(['quantity' => 1]); // cart wants 5, only 1 left

        [$status] = $this->checkout();
        $this->assertSame(422, $status);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, SubOrder::count());
        $this->assertSame(0, OrderItem::count());
        // Pharmacy A's stock must be untouched (atomic rollback).
        $this->assertEquals(100, $this->pmA->fresh()->quantity);
        $this->assertEquals(1, $this->pmB->fresh()->quantity);
    }

    public function test_multi_pharmacy_checkout_rolls_back_on_price_change_in_one_pharmacy(): void
    {
        $this->seedTwoPharmacyCart();
        $this->pmB->update(['price' => 35.00]); // price changed since cart

        [$status, $body] = $this->checkout();
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('price_changed', $body['errors']);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, SubOrder::count());
        $this->assertEquals(100, $this->pmA->fresh()->quantity);
        $this->assertEquals(100, $this->pmB->fresh()->quantity);
    }

    public function test_multi_pharmacy_checkout_decrements_each_pharmacy_stock(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $this->assertEquals(98, $this->pmA->fresh()->quantity); // 100 - 2
        $this->assertEquals(99, $this->pmB->fresh()->quantity); // 100 - 1
    }

    // ── Idempotency across sub-orders ────────────────────────────────────────

    public function test_idempotent_replay_does_not_duplicate_sub_orders(): void
    {
        $this->seedTwoPharmacyCart();

        [$s1, $b1] = $this->checkout(['idempotency_key' => 'mp-dup']);
        [$s2, $b2] = $this->checkout(['idempotency_key' => 'mp-dup']);

        $this->assertSame(201, $s1);
        $this->assertSame(201, $s2);
        $this->assertSame($b1['data']['order_id'], $b2['data']['order_id']);
        $this->assertTrue($b2['data']['idempotent'] ?? false);

        $this->assertSame(1, Order::count());
        $this->assertSame(2, SubOrder::count());
    }

    // ── Coupon discount distribution ─────────────────────────────────────────

    /**
     * Phase 0 — a coupon worth more than the cart must not push the parent total
     * or any sub-order total below zero, and the allocation must stay exact.
     */
    public function test_oversized_coupon_keeps_parent_and_sub_order_totals_non_negative(): void
    {
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'OVER500',
            'type' => 'fixed',
            'value' => 500, // cart is 130.00 → discount must cap at 130.00
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->seedTwoPharmacyCart(); // A×2 (100.00) + B×1 (30.00) = 130.00

        [$status] = $this->checkout(['coupon_code' => $coupon->code]);
        $this->assertSame(201, $status);

        $order = Order::firstOrFail();

        $this->assertEquals(130.0, (float) $order->subtotal);
        $this->assertEquals(130.0, (float) $order->discount, 'Discount must cap at the eligible subtotal');
        $this->assertEquals(0.0, (float) $order->total);
        $this->assertGreaterThanOrEqual(0.0, (float) $order->total);

        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        foreach ([$subA, $subB] as $subOrder) {
            $this->assertGreaterThanOrEqual(0.0, (float) $subOrder->total);
            $this->assertLessThanOrEqual((float) $subOrder->subtotal, (float) $subOrder->discount);
        }

        // The proportional allocation is unchanged: sub-order money still sums
        // to the parent exactly, with no cent lost.
        $this->assertEquals((float) $order->discount, (float) ($subA->discount + $subB->discount));
        $this->assertEquals((float) $order->total, (float) ($subA->total + $subB->total));
    }

    public function test_coupon_discount_is_distributed_across_sub_orders(): void
    {
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'MP10',
            'type' => 'percentage',
            'value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->seedTwoPharmacyCart();

        [$status] = $this->checkout(['coupon_code' => $coupon->code]);
        $this->assertSame(201, $status);

        $order = Order::firstOrFail();
        $this->assertEquals(13.0, (float) $order->discount);
        $this->assertEquals(117.0, (float) $order->total);

        $subA = $this->subOrderFor($order, $this->pharmacyA); // 100/130 → 10.00
        $subB = $this->subOrderFor($order, $this->pharmacyB); // remainder → 3.00

        $this->assertEquals(10.0, (float) $subA->discount);
        $this->assertEquals(3.0, (float) $subB->discount);

        // Sum of sub-order money must equal the parent exactly (no cent lost).
        $this->assertEquals((float) $order->discount, (float) ($subA->discount + $subB->discount));
        $this->assertEquals((float) $order->total, (float) ($subA->total + $subB->total));
    }

    public function test_discount_distribution_keeps_sum_equal_with_odd_split(): void
    {
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'ODD7',
            'type' => 'fixed',
            'value' => 7,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        // A: 50×1 = 50, B: 30×1 = 30, C(another pharmacy): 20×1 = 20 → 100 total
        // Phase 2 — C داخل حد التقارب أيضاً (≈334 م من A، ≈167 م من B).
        $pharmacyC = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => 31.5030,
            'longitude' => 34.4700,
        ]);
        $pmC = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacyC->id,
            'quantity' => 50,
            'is_available' => true,
            'price' => 20.00,
        ]);

        $this->seedCart([
            ['pm' => $this->pmA, 'quantity' => 1],
            ['pm' => $this->pmB, 'quantity' => 1],
            ['pm' => $pmC, 'quantity' => 1],
        ]);

        [$status] = $this->checkout(['coupon_code' => $coupon->code]);
        $this->assertSame(201, $status);

        $order = Order::firstOrFail();
        $this->assertEquals(7.0, (float) $order->discount);

        $sumDiscount = (float) $order->subOrders()->sum('discount');
        $sumTotal = (float) $order->subOrders()->sum('total');

        $this->assertEquals(7.0, round($sumDiscount, 2), 'sub-order discounts must sum to the parent discount');
        $this->assertEquals((float) $order->total, round($sumTotal, 2), 'sub-order totals must sum to the parent total');
    }

    // ── Stage C — independent states + parent derivation ─────────────────────

    public function test_pharmacy_updates_its_own_sub_order_and_parent_waits_for_bottleneck(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->advance($subA, $this->pharmacyA->user, [SubOrder::STATUS_PREPARING]);

        $this->assertSame(SubOrder::STATUS_PREPARING, $subA->fresh()->status);
        // B is still confirmed → parent stays at the least-advanced active step.
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
    }

    public function test_parent_advances_when_all_pharmacies_reach_the_same_step(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->advance($subA, $this->pharmacyA->user, [SubOrder::STATUS_PREPARING]);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);

        $this->advance($subB, $this->pharmacyB->user, [SubOrder::STATUS_PREPARING]);
        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status);
    }

    public function test_parent_becomes_delivered_only_when_all_active_sub_orders_delivered(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $chain = [
            SubOrder::STATUS_PREPARING,
            SubOrder::STATUS_READY,
            SubOrder::STATUS_OUT_FOR_DELIVERY,
            SubOrder::STATUS_DELIVERED,
        ];

        $this->advance($subA, $this->pharmacyA->user, $chain);
        $this->assertNotSame(Order::STATUS_DELIVERED, $order->fresh()->status, 'parent must wait for B');

        $this->advance($subB, $this->pharmacyB->user, $chain);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_parent_cancelled_when_all_sub_orders_cancelled(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->advance($subA, $this->pharmacyA->user, [SubOrder::STATUS_CANCELLED]);
        $this->assertNotSame(Order::STATUS_CANCELLED, $order->fresh()->status, 'B still active');

        $this->advance($subB, $this->pharmacyB->user, [SubOrder::STATUS_CANCELLED]);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_parent_delivered_when_remaining_sub_order_delivered_and_other_cancelled(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->advance($subB, $this->pharmacyB->user, [SubOrder::STATUS_CANCELLED]);
        $this->advance($subA, $this->pharmacyA->user, [
            SubOrder::STATUS_PREPARING,
            SubOrder::STATUS_READY,
            SubOrder::STATUS_OUT_FOR_DELIVERY,
            SubOrder::STATUS_DELIVERED,
        ]);

        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_sub_order_invalid_transition_is_rejected(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        // confirmed → delivered directly is not allowed.
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_DELIVERED,
        ])->assertStatus(422);

        $this->assertSame(SubOrder::STATUS_CONFIRMED, $subA->fresh()->status);
    }

    // ── Stage D — authorization & isolation ──────────────────────────────────

    public function test_pharmacy_cannot_update_another_pharmacys_sub_order(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])->assertStatus(403);

        $this->assertSame(SubOrder::STATUS_CONFIRMED, $subA->fresh()->status);
    }

    public function test_pharmacy_sub_order_list_returns_only_its_own(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $resp = $this->getJson('/api/pharmacy/sub-orders')->assertStatus(200);

        $this->assertSame(1, $resp->json('pagination.total'));
        $this->assertSame($this->pharmacyA->id, $resp->json('data.0.pharmacy_id'));

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $respB = $this->getJson('/api/pharmacy/sub-orders')->assertStatus(200);
        $this->assertSame(1, $respB->json('pagination.total'));
        $this->assertSame($this->pharmacyB->id, $respB->json('data.0.pharmacy_id'));
    }

    public function test_legacy_parent_status_route_resolves_own_sub_order(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        // Pharmacy A uses the legacy parent route → its own sub-order moves.
        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])->assertStatus(200);

        $this->assertSame(SubOrder::STATUS_PREPARING, $subA->fresh()->status);
        $this->assertSame(SubOrder::STATUS_CONFIRMED, $subB->fresh()->status, 'B untouched');

        // A pharmacy with no sub-order in this order gets 403.
        $outsider = Pharmacy::factory()->create(['is_active' => true]);
        $this->actingAs($outsider->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => SubOrder::STATUS_PREPARING,
        ])->assertStatus(403);
    }

    public function test_patient_show_exposes_sub_orders_and_flag(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $resp = $this->getJson("/api/patient/orders/{$order->id}")->assertStatus(200);

        $this->assertTrue($resp->json('data.is_multi_pharmacy'));
        $this->assertCount(2, $resp->json('data.sub_orders'));
        $this->assertSame($this->pharmacyA->id, $resp->json('data.sub_orders.0.pharmacy_id'));
    }

    public function test_tracking_exposes_per_sub_order_timelines(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $this->advance($subA, $this->pharmacyA->user, [SubOrder::STATUS_PREPARING]);

        $this->actingAs($this->patient, 'sanctum');
        $resp = $this->getJson("/api/patient/orders/{$order->id}/tracking")->assertStatus(200);

        $this->assertTrue($resp->json('data.is_multi_pharmacy'));
        $this->assertCount(2, $resp->json('data.sub_orders'));

        $timelines = collect($resp->json('data.sub_orders'));
        $forA = $timelines->firstWhere('pharmacy_id', $this->pharmacyA->id);
        $this->assertNotEmpty($forA['status_histories']);
        $this->assertSame(SubOrder::STATUS_PREPARING, $forA['status']);
    }

    public function test_patient_cancel_cancels_all_active_sub_orders(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();

        $order = Order::firstOrFail();

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(
            0,
            $order->subOrders()->where('status', '!=', SubOrder::STATUS_CANCELLED)->count(),
            'every sub-order must be cancelled with the parent'
        );
    }

    public function test_multi_pharmacy_cancel_restores_each_pharmacy_stock_once(): void
    {
        $this->seedTwoPharmacyCart(); // A×2 + B×1

        $beforeA = (int) $this->pmA->quantity; // 100
        $beforeB = (int) $this->pmB->quantity; // 100

        $this->checkout();
        $order = Order::firstOrFail();

        $this->assertSame($beforeA - 2, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB - 1, (int) $this->pmB->fresh()->quantity);

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        // Each pharmacy gets exactly its own quantity back. Restoration happens
        // per sub-order, so the parent level must not add a second helping.
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB, (int) $this->pmB->fresh()->quantity);

        // A second cancel is rejected and must not restock again.
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB, (int) $this->pmB->fresh()->quantity);
    }

    public function test_pharmacy_cancelling_its_sub_order_restores_only_its_own_stock(): void
    {
        $this->seedTwoPharmacyCart(); // A×2 + B×1

        $beforeA = (int) $this->pmA->quantity;
        $beforeB = (int) $this->pmB->quantity;

        $this->checkout();
        $order = Order::firstOrFail();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(200);

        // Only A's stock returns; B's stays deducted.
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB - 1, (int) $this->pmB->fresh()->quantity);

        $this->assertSame(SubOrder::STATUS_CANCELLED, $subA->fresh()->status);
        $this->assertNotSame(
            SubOrder::STATUS_CANCELLED,
            $this->subOrderFor($order, $this->pharmacyB)->status
        );

        // Repeating the cancel is rejected and must not restock A again.
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => SubOrder::STATUS_CANCELLED,
        ])->assertStatus(422);
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
    }

    /**
     * Phase 0 — a parent order that has sub-orders can still carry a LEGACY
     * parent-level line (`sub_order_id = NULL`). The Phase 1.2 remediation
     * downgrades any line whose composite link does not resolve to exactly that
     * shape (`2026_10_15_000002_...`, "downgraded to a LEGACY row"). Its stock was
     * deducted at checkout, and the sub-order branch alone would never return it.
     */
    public function test_parent_level_legacy_item_is_restored_when_the_parent_is_cancelled(): void
    {
        $this->seedTwoPharmacyCart(); // A×2 (100.00) + B×1 (30.00)

        $beforeA = (int) $this->pmA->quantity; // 100
        $beforeB = (int) $this->pmB->quantity; // 100

        $this->checkout();
        $order = Order::firstOrFail();

        $this->assertSame($beforeA - 2, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB - 1, (int) $this->pmB->fresh()->quantity);

        // Reproduce the remediation outcome: A's line is downgraded to a legacy
        // parent-level row. `sub_order_id` is nullable and the composite FK is
        // satisfied by a NULL component, so this is a valid row — not a fabricated
        // one. Sub-order A is left with no items of its own.
        $legacyItem = OrderItem::where('order_id', $order->id)
            ->where('pharmacy_id', $this->pharmacyA->id)
            ->firstOrFail();
        $legacyItem->update(['sub_order_id' => null]);

        $this->assertNull($legacyItem->fresh()->sub_order_id);
        $this->assertSame(0, $this->subOrderFor($order, $this->pharmacyA)->items()->count());

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        // A's quantity comes back through the parent-level sweep, B's through its
        // own sub-order — each exactly once, by the exact deducted quantity.
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB, (int) $this->pmB->fresh()->quantity);

        // Statuses stay consistent with the existing cancellation behaviour.
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(
            0,
            $order->subOrders()->where('status', '!=', SubOrder::STATUS_CANCELLED)->count()
        );

        // A repeated cancel must not restore either line a second time.
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame($beforeA, (int) $this->pmA->fresh()->quantity);
        $this->assertSame($beforeB, (int) $this->pmB->fresh()->quantity);
    }

    public function test_patient_cannot_view_another_patients_multi_pharmacy_order(): void
    {
        $this->seedTwoPharmacyCart();
        $this->checkout();
        $order = Order::firstOrFail();

        $other = User::factory()->patient()->create();
        $this->actingAs($other, 'sanctum');

        $this->getJson("/api/patient/orders/{$order->id}")->assertStatus(404);
    }

    // ── Legacy (pre-Phase-1) orders ──────────────────────────────────────────

    public function test_legacy_order_without_sub_orders_still_works(): void
    {
        // Simulate an order created BEFORE Phase 1: no sub-orders, items hang
        // directly off the order, and pharmacy_id is set on the parent.
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

        // Patient can still view it, with an empty (not missing) sub_orders list.
        $resp = $this->getJson("/api/patient/orders/{$order->id}")->assertStatus(200);
        $this->assertCount(0, $resp->json('data.sub_orders'));
        $this->assertFalse($resp->json('data.is_multi_pharmacy'));

        // Pharmacy can still advance the parent directly.
        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => Order::STATUS_PREPARING,
        ])->assertStatus(200);

        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status);
        // Legacy item keeps a NULL sub_order_id (no backfill required).
        $this->assertNull($order->items()->first()->sub_order_id);
    }

    public function test_legacy_order_pharmacy_isolation_still_enforced(): void
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

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->postJson("/api/pharmacy/orders/{$order->id}/status", [
            'status' => Order::STATUS_PREPARING,
        ])->assertStatus(403);
    }
}
