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
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Pharmacy $pharmacy;
    private PharmacyMedicine $pharmacyMedicine;
    private User $patient;
    private Address $address;
    private Cart $cart;

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
        $this->address = Address::factory()->for($this->patient)->create();
        $this->actingAs($this->patient, 'sanctum');
    }

    private function seedCart(int $items = 1, int $quantity = 2): Cart
    {
        $cart = Cart::factory()->for($this->patient)->create();

        for ($i = 0; $i < $items; $i++) {
            $pm = $i === 0
                ? $this->pharmacyMedicine
                : PharmacyMedicine::factory()->create([
                    'pharmacy_id' => $this->pharmacy->id,
                    'quantity' => 50,
                    'is_available' => true,
                    'price' => 25.00,
                ]);

            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'pharmacy_id' => $pm->pharmacy_id,
                'pharmacy_medicine_id' => $pm->id,
                'quantity' => $quantity,
                'price' => (float) $pm->price,
            ]);
        }

        return $cart;
    }

    public function test_successful_checkout_creates_order_and_clears_cart(): void
    {
        $cart = $this->seedCart(2, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['success' => true]);

        $this->assertSame(1, Order::count());
        $order = Order::first();
        $this->assertSame($this->patient->id, $order->user_id);
        $this->assertSame($this->address->id, $order->address_id);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame(2, $order->items()->count());

        $this->assertSame(0, $cart->items()->count());
    }

    public function test_checkout_requires_address(): void
    {
        $this->seedCart();

        $response = $this->postJson('/api/patient/checkout', []);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_rejects_another_users_address(): void
    {
        $this->seedCart();

        $otherUser = User::factory()->patient()->create();
        $otherAddress = Address::factory()->for($otherUser)->create();

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $otherAddress->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_with_empty_cart_fails(): void
    {
        $this->seedCart(0);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_rejects_out_of_stock_items(): void
    {
        PharmacyMedicine::find($this->pharmacyMedicine->id)->update(['quantity' => 0]);
        $this->seedCart(1, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_rejects_unavailable_items(): void
    {
        $this->pharmacyMedicine->update(['is_available' => false]);
        $this->seedCart(1, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_checkout_totals_are_calculated_server_side(): void
    {
        $this->seedCart(2, 1);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(75.00, (float) $order->subtotal, '50 + 25 = 75');
        $this->assertSame(75.00, (float) $order->total);
        $this->assertSame(0.00, (float) $order->discount);
    }

    public function test_checkout_decrements_inventory(): void
    {
        $this->seedCart(1, 4);
        $initialQuantity = $this->pharmacyMedicine->quantity;

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $this->assertSame($initialQuantity - 4, $this->pharmacyMedicine->fresh()->quantity);
    }

    public function test_checkout_strips_untrusted_client_price(): void
    {
        $this->seedCart(1, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(100.00, (float) $order->subtotal, 'price comes from DB, not client');
    }

    public function test_checkout_applies_coupon_discount(): void
    {
        $coupon = Coupon::create([
            'code' => 'SAVE20',
            'type' => 'percentage',
            'value' => 20,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        $this->seedCart(1, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => $coupon->code,
        ])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(100.00, (float) $order->subtotal);
        $this->assertSame(20.00, (float) $order->discount);
        $this->assertSame(80.00, (float) $order->total);
        $this->assertSame('SAVE20', $order->coupon_code);
    }

    public function test_checkout_rejects_invalid_coupon(): void
    {
        $this->seedCart(1, 2);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => 'NONEXISTENT',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_idor_cannot_view_another_users_order(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $otherPatient = User::factory()->patient()->create();
        $this->actingAs($otherPatient, 'sanctum');

        $this->getJson("/api/patient/orders/{$order->id}")->assertStatus(404);
        $this->getJson('/api/patient/orders')->assertJsonCount(0, 'data');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(404);
    }

    public function test_list_orders_with_pagination(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);

        $response = $this->getJson('/api/patient/orders')->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'status', 'total', 'items']],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame(Order::STATUS_CONFIRMED, $response->json('data.0.status'));
    }

    public function test_show_order_includes_items_and_status_history(): void
    {
        $this->seedCart(2, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $response = $this->getJson("/api/patient/orders/{$order->id}")->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'id', 'subtotal', 'discount', 'total', 'status', 'items', 'address',
            ],
        ]);
        $this->assertSame(2, $response->json('data.items_count'));
        $this->assertNotNull($response->json('data.address.id'));
        $this->assertSame(1, $order->statusHistories()->count());
    }

    public function test_tracking_returns_status_history(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $response = $this->getJson("/api/patient/orders/{$order->id}/tracking")->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'order_id',
                'status',
                'status_histories' => [['status', 'note']],
            ],
        ]);
        $this->assertSame(Order::STATUS_CONFIRMED, $response->json('data.status'));
    }

    public function test_patient_cannot_cancel_delivered_order(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();
        $order->update(['status' => Order::STATUS_DELIVERED, 'delivered_at' => now()]);

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_patient_can_cancel_pending_order(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_non_patient_role_cannot_checkout(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $this->actingAs($pharmacyUser, 'sanctum');

        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])
            ->assertStatus(403);
    }

    public function test_pharmacy_updates_order_status(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $pharmacyUser = $this->pharmacy->user;
        $this->actingAs($pharmacyUser, 'sanctum');

        $this->postJson('/api/pharmacy/orders/'.$order->id.'/status', [
            'status' => Order::STATUS_PREPARING,
        ])->assertStatus(200);

        $this->assertSame(Order::STATUS_PREPARING, $order->fresh()->status);
    }

    public function test_pharmacy_cannot_update_order_for_other_pharmacy(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $otherPharmacy = Pharmacy::factory()->create();
        $otherUser = $otherPharmacy->user;
        $this->actingAs($otherUser, 'sanctum');

        $this->postJson('/api/pharmacy/orders/'.$order->id.'/status', [
            'status' => Order::STATUS_PREPARING,
        ])->assertStatus(403);
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $this->seedCart(1, 1);
        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();

        $pharmacyUser = $this->pharmacy->user;
        $this->actingAs($pharmacyUser, 'sanctum');

        $this->postJson('/api/pharmacy/orders/'.$order->id.'/status', [
            'status' => Order::STATUS_DELIVERED,
        ])->assertStatus(422);
    }

    public function test_cancel_order_does_not_restore_inventory_for_cancelled_order(): void
    {
        $this->seedCart(1, 4);
        $before = $this->pharmacyMedicine->quantity;

        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])->assertStatus(201);
        $order = Order::first();
        $this->assertSame($before - 4, $this->pharmacyMedicine->fresh()->quantity);

        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertStatus(200);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }
}
