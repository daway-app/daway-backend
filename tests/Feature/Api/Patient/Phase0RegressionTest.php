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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 0 regression tests:
 *  - address protection + snapshot
 *  - checkout price re-verification from the locked DB row
 *  - idempotency on checkout
 *  - coupon usage limits (global + per-user)
 *  - currency label (₪) in coupon validation message
 */
class Phase0RegressionTest extends TestCase
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

    private function seedCart(int $quantity = 2): Cart
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
        return $cart;
    }

    private function checkout(array $extra = []): array
    {
        $payload = array_merge([
            'address_id' => $this->address->id,
            'idempotency_key' => 'test-key',
        ], $extra);

        $resp = $this->postJson('/api/patient/checkout', $payload);
        return [$resp->status(), $resp->json()];
    }

    // ── 1. Address snapshot ──────────────────────────────────────────────

    public function test_checkout_stores_independent_address_snapshot(): void
    {
        $this->seedCart();
        [$status, $body] = $this->checkout(['idempotency_key' => 'snapshot-1']);
        $this->assertEquals(201, $status);

        $order = Order::first();
        $this->assertNotNull($order->address_snapshot);
        $this->assertSame('عبود', $order->address_snapshot['recipient_name']);
        $this->assertSame('غزة، فلسطين', $order->address_snapshot['address']);
        $this->assertSame(31.5016, $order->address_snapshot['latitude']);
    }

    public function test_editing_address_after_checkout_does_not_change_order_snapshot(): void
    {
        $this->seedCart();
        $this->checkout(['idempotency_key' => 'snapshot-edit']);
        $order = Order::first();

        // Edit the source address.
        $this->address->update(['recipient_name' => 'محمد', 'address' => 'رام الله']);
        $order->refresh();

        $this->assertSame('عبود', $order->address_snapshot['recipient_name']);
        $this->assertSame('غزة، فلسطين', $order->address_snapshot['address']);
        // The live FK still points to the same address id (now edited).
        $this->assertSame($this->address->id, $order->address_id);
    }

    public function test_deleting_address_after_checkout_does_not_delete_order(): void
    {
        $this->seedCart();
        $this->checkout(['idempotency_key' => 'snapshot-delete']);
        $order = Order::first();
        $this->assertNotNull($order);

        $this->address->delete();
        $order->refresh();

        // address_id should be NULL now (nullOnDelete), NOT the order deleted.
        $this->assertNull($order->address_id);
        $this->assertNotNull($order->address_snapshot);
        $this->assertTrue($order->exists);
    }

    // ── 2. Price re-verification ───────────────────────────────────────────

    public function test_checkout_rejects_if_price_rose_since_cart(): void
    {
        $this->seedCart();
        // Simulate another pharmacy process raising the price.
        $this->pharmacyMedicine->update(['price' => 60.00]);

        [$status, $body] = $this->checkout(['idempotency_key' => 'price-rose']);
        $this->assertEquals(422, $status);
        $this->assertArrayHasKey('price_changed', $body['errors'] ?? []);

        // No order + no stock decrement.
        $this->assertEquals(0, Order::count());
        $this->pharmacyMedicine->refresh();
        $this->assertEquals(100, $this->pharmacyMedicine->quantity);
    }

    public function test_checkout_rejects_if_inventory_insufficient(): void
    {
        $this->seedCart(5); // cart wants 5
        $this->pharmacyMedicine->update(['quantity' => 3]); // only 3 available

        [$status, $body] = $this->checkout(['idempotency_key' => 'low-stock']);
        $this->assertEquals(422, $status);
        $this->assertEquals(0, Order::count());
    }

    public function test_checkout_accepts_if_price_unchanged(): void
    {
        $this->seedCart();
        [$status, $body] = $this->checkout(['idempotency_key' => 'price-ok']);
        $this->assertEquals(201, $status);
        $this->assertEquals(50 * 2, $body['data']['total']);
    }

    // ── 3. Idempotency ──────────────────────────────────────────────────

    public function test_idempotent_replay_returns_same_order(): void
    {
        $this->seedCart();
        [$s1, $b1] = $this->checkout(['idempotency_key' => 'dup-key']);
        $firstId = $b1['data']['order_id'];

        [$s2, $b2] = $this->checkout(['idempotency_key' => 'dup-key']);
        $secondId = $b2['data']['order_id'] ?? null;
        $this->assertEquals(201, $s1);
        $this->assertEquals(201, $s2);
        $this->assertSame($firstId, $secondId);
        $this->assertTrue($b2['data']['idempotent'] ?? false);
        $this->assertEquals(1, Order::count());
    }

    public function test_different_keys_create_different_orders(): void
    {
        // Cart is cleared after checkout, so re-seed for the second attempt.
        $this->seedCart();
        $this->checkout(['idempotency_key' => 'a']);

        $this->seedCart();
        $this->checkout(['idempotency_key' => 'b']);

        $this->assertEquals(2, Order::count());
    }

    // ── 4. Coupon usage limits ───────────────────────────────────────────

    public function test_global_usage_limit_is_enforced(): void
    {
        $cart = $this->seedCart();
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'GLOBAL_LIMIT',
            'type' => 'fixed',
            'value' => 5,
            'minimum_order_amount' => 0,
            'usage_limit' => 1,
            'used_count' => 1, // already exhausted
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => 'GLOBAL_LIMIT',
            'idempotency_key' => 'coupon-global',
        ]);

        $resp->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    public function test_per_user_limit_is_enforced(): void
    {
        $cart = $this->seedCart();
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'USER_LIMIT',
            'type' => 'fixed',
            'value' => 5,
            'minimum_order_amount' => 0,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        // Pre-mark as used by THIS patient.
        \DB::table('coupon_user')->insert([
            'coupon_id' => $coupon->id,
            'user_id' => $this->patient->id,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resp = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => 'USER_LIMIT',
            'idempotency_key' => 'coupon-user',
        ]);

        $resp->assertStatus(422)
            ->assertJsonFragment(['message' => 'لست مؤهلاً لاستخدام هذا الكوبون']);
    }

    public function test_successful_checkout_increments_used_count(): void
    {
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'VALID_COUPON',
            'type' => 'fixed',
            'value' => 5,
            'minimum_order_amount' => 0,
            'usage_limit' => 10,
            'used_count' => 3,
            'is_active' => true,
        ]);

        $this->seedCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => 'VALID_COUPON',
            'idempotency_key' => 'coupon-inc-' . uniqid(),
        ])->assertStatus(201);

        $coupon->refresh();
        $this->assertEquals(4, $coupon->used_count);
    }

    // ── 5. Currency label ────────────────────────────────────────────────

    public function test_coupon_error_message_uses_ils_symbol(): void
    {
        Coupon::query()->delete();
        $coupon = Coupon::create([
            'code' => 'MIN_CHECK',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 100,
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'MIN_CHECK',
            'order_total' => 50,
        ]);

        $resp->assertStatus(422)
            ->assertJsonFragment(['message' => 'الحد الأدنى للطلب هو 100.00 ₪']);
    }

    // ── 6. Coupon discount cap — totals can never go negative ────────────

    public function test_fixed_coupon_larger_than_subtotal_cannot_make_total_negative(): void
    {
        Coupon::query()->delete();
        Coupon::create([
            'code' => 'HUGE500',
            'type' => 'fixed',
            'value' => 500,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->seedCart(2); // 50.00 × 2 = 100.00

        [$status] = $this->checkout([
            'coupon_code' => 'HUGE500',
            'idempotency_key' => 'cap-fixed',
        ]);
        $this->assertEquals(201, $status);

        $order = Order::firstOrFail();
        $this->assertSame(100.0, (float) $order->subtotal);
        // The discount is capped at the eligible subtotal, so the payable total
        // is exactly zero — never negative.
        $this->assertSame(100.0, (float) $order->discount);
        $this->assertSame(0.0, (float) $order->total);
    }

    public function test_percentage_coupon_above_100_cannot_make_total_negative(): void
    {
        Coupon::query()->delete();
        Coupon::create([
            'code' => 'OVER150',
            'type' => 'percentage',
            'value' => 150, // no upper bound on `value`, so >100% is configurable
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->seedCart(2); // 100.00

        [$status] = $this->checkout([
            'coupon_code' => 'OVER150',
            'idempotency_key' => 'cap-percent',
        ]);
        $this->assertEquals(201, $status);

        $order = Order::firstOrFail();
        $this->assertSame(100.0, (float) $order->discount);
        $this->assertSame(0.0, (float) $order->total);
    }

    public function test_applied_discount_never_exceeds_eligible_subtotal(): void
    {
        Coupon::query()->delete();
        Coupon::create([
            'code' => 'CAPCHECK',
            'type' => 'fixed',
            'value' => 500,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->seedCart(2); // 100.00

        [$status] = $this->checkout([
            'coupon_code' => 'CAPCHECK',
            'idempotency_key' => 'cap-check',
        ]);
        $this->assertEquals(201, $status);

        $order = Order::firstOrFail();
        $this->assertLessThanOrEqual((float) $order->subtotal, (float) $order->discount);
        $this->assertGreaterThanOrEqual(0.0, (float) $order->total);

        foreach ($order->subOrders as $subOrder) {
            $this->assertGreaterThanOrEqual(0.0, (float) $subOrder->total);
        }
    }

    // ── 7. Inventory restoration on cancellation ─────────────────────────

    public function test_cancel_restores_exactly_the_deducted_quantity_per_line(): void
    {
        // A second line, on its own pharmacy_medicine row, to prove each line is
        // restored to its OWN row with its OWN quantity.
        $second = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 40,
            'is_available' => true,
            'price' => 12.50,
        ]);

        $cart = Cart::firstOrCreate(['user_id' => $this->patient->id], ['items_count' => 0, 'subtotal' => 0]);
        $cart->items()->delete();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 3,
            'price' => 50.00,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $second->pharmacy_id,
            'pharmacy_medicine_id' => $second->id,
            'quantity' => 2,
            'price' => 12.50,
        ]);

        $beforeFirst = (int) $this->pharmacyMedicine->quantity; // 100
        $beforeSecond = (int) $second->quantity;                // 40

        [$status] = $this->checkout(['idempotency_key' => 'restore-lines']);
        $this->assertEquals(201, $status);
        $this->assertSame($beforeFirst - 3, (int) $this->pharmacyMedicine->fresh()->quantity);
        $this->assertSame($beforeSecond - 2, (int) $second->fresh()->quantity);

        $order = Order::firstOrFail();
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame($beforeFirst, (int) $this->pharmacyMedicine->fresh()->quantity);
        $this->assertSame($beforeSecond, (int) $second->fresh()->quantity);
    }

    public function test_repeated_cancellation_does_not_restore_stock_twice(): void
    {
        $this->seedCart(2);
        $before = (int) $this->pharmacyMedicine->quantity;

        [$status] = $this->checkout(['idempotency_key' => 'restore-dup']);
        $this->assertEquals(201, $status);

        $order = Order::firstOrFail();

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);
        $afterFirst = (int) $this->pharmacyMedicine->fresh()->quantity;
        $this->assertSame($before, $afterFirst);

        // Every further attempt is rejected and must not restock again.
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame($afterFirst, (int) $this->pharmacyMedicine->fresh()->quantity);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_does_not_restore_stock_for_an_order_still_pending(): void
    {
        // `pending` means the checkout transaction never completed, so no stock
        // was ever deducted. Item rows alone must not be treated as a deduction.
        $order = Order::create([
            'user_id' => $this->patient->id,
            'address_id' => $this->address->id,
            'pharmacy_id' => $this->pharmacy->id,
            'subtotal' => 250.00,
            'discount' => 0,
            'total' => 250.00,
            'status' => Order::STATUS_PENDING,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'medicine_trade_name' => 'Panadol 500mg',
            'quantity' => 5,
            'unit_price' => 50.00,
        ]);

        $before = (int) $this->pharmacyMedicine->quantity; // untouched: 100

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame($before, (int) $this->pharmacyMedicine->fresh()->quantity);
    }

    public function test_cancel_does_not_restore_stock_for_an_order_without_items(): void
    {
        $order = Order::create([
            'user_id' => $this->patient->id,
            'address_id' => $this->address->id,
            'pharmacy_id' => $this->pharmacy->id,
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'status' => Order::STATUS_CONFIRMED,
        ]);

        $before = (int) $this->pharmacyMedicine->quantity;

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame($before, (int) $this->pharmacyMedicine->fresh()->quantity);
    }

    public function test_unauthorized_or_invalid_cancellation_does_not_change_inventory(): void
    {
        $this->seedCart(2);
        [$status] = $this->checkout(['idempotency_key' => 'restore-auth']);
        $this->assertEquals(201, $status);

        $order = Order::firstOrFail();
        $afterCheckout = (int) $this->pharmacyMedicine->fresh()->quantity;

        // Another patient may not cancel it, and the stock must stay deducted.
        $other = User::factory()->patient()->create();
        $this->actingAs($other, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(404);
        $this->assertSame($afterCheckout, (int) $this->pharmacyMedicine->fresh()->quantity);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);

        // A completed order cannot be cancelled either.
        $this->actingAs($this->patient, 'sanctum');
        $order->update(['status' => Order::STATUS_DELIVERED, 'delivered_at' => now()]);
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame($afterCheckout, (int) $this->pharmacyMedicine->fresh()->quantity);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }
}
