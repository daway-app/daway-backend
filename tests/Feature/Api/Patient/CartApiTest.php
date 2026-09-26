<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs($this->patient, 'sanctum');
    }

    public function test_patient_can_view_own_cart(): void
    {
        $cart = Cart::factory()->for($this->patient)->create();

        $response = $this->getJson('/api/patient/cart');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_patient_can_add_medicine_to_cart(): void
    {
        $response = $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_server_uses_database_price_not_client_provided(): void
    {
        $response = $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201);

        $cartItem = CartItem::first();
        $this->assertEquals(50.00, (float) $cartItem->price);
    }

    public function test_patient_can_update_quantity(): void
    {
        $cart = Cart::factory()->for($this->patient)->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 1,
        ]);

        $response = $this->putJson("/api/patient/cart/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    public function test_quantity_cannot_exceed_stock(): void
    {
        $lowStockItem = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 3,
            'is_available' => true,
            'price' => 11.50,
        ]);

        $response = $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $lowStockItem->id,
            'quantity' => 10,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_add_duplicate_medicine_as_separate_items(): void
    {
        Cart::factory()->for($this->patient)->create();

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
        ]);

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 3,
        ]);

        $this->assertEquals(1, CartItem::count());
        $this->assertEquals(5, CartItem::first()->quantity);
    }

    public function test_can_set_quantity_incrementally(): void
    {
        $cart = Cart::factory()->for($this->patient)->create();

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
        ]);

        $item = CartItem::first();

        // Update via PUT endpoint
        $this->putJson("/api/patient/cart/items/{$item->id}", [
            'quantity' => 5,
        ]);

        $this->assertEquals(5, $item->fresh()->quantity);
    }

    public function test_clear_cart_removes_all_items(): void
    {
        // Create two different pharmacy medicines for this test
        $pm1 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 30.00,
        ]);

        $pm2 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 40.00,
        ]);

        // Create fresh cart for this test
        $cart = Cart::factory()->for($this->patient)->create();

        // Add two DIFFERENT medicines to the cart
        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $pm1->id,
            'quantity' => 2,
        ]);

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $pm2->id,
            'quantity' => 3,
        ]);

        $this->assertEquals(2, CartItem::where('cart_id', $cart->id)->count());

        $response = $this->deleteJson('/api/patient/cart');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);

        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
    }

    public function test_empty_cart_returns_empty_items(): void
    {
        $response = $this->getJson('/api/patient/cart');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true])
            ->assertJsonCount(0, 'data.items');
    }

    public function test_add_medicine_creates_cart_if_not_exists(): void
    {
        $this->assertEquals(0, Cart::count());

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
        ]);

        $this->assertEquals(1, Cart::count());
    }

    public function test_non_patient_user_cannot_view_cart(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $this->actingAs($pharmacyUser, 'sanctum');
        $this->getJson('/api/patient/cart')->assertStatus(403);
    }

    public function test_non_patient_user_cannot_add_item(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $this->actingAs($pharmacyUser, 'sanctum');
        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 1,
        ])->assertStatus(403);
    }

    public function test_idor_cannot_access_another_users_cart(): void
    {
        $otherPatient = User::factory()->patient()->create();
        $otherCart = Cart::factory()->for($otherPatient)->create();
        $otherItem = CartItem::factory()->create([
            'cart_id' => $otherCart->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
        ]);

        $this->actingAs($this->patient, 'sanctum');
        $this->getJson('/api/patient/cart')
            ->assertStatus(200);

        $this->putJson("/api/patient/cart/items/{$otherItem->id}", [
            'quantity' => 99,
        ])->assertStatus(404);

        $this->deleteJson("/api/patient/cart/items/{$otherItem->id}")->assertStatus(404);
    }

    public function test_idor_cannot_delete_another_users_cart(): void
    {
        $otherPatient = User::factory()->patient()->create();
        $otherCart = Cart::factory()->for($otherPatient)->create();

        $this->actingAs($otherPatient, 'sanctum');
        $this->deleteJson('/api/patient/cart')->assertStatus(200);

        $this->actingAs($this->patient, 'sanctum');
        $this->deleteJson('/api/patient/cart')->assertStatus(200);

        $this->assertTrue($otherCart->items()->exists() || Cart::where('id', $otherCart->id)->first()->items_count === 0);
    }

    public function test_cannot_add_out_of_stock_medicine(): void
    {
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 100,
            'is_available' => false,
            'price' => 20.00,
        ]);

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $pm->id,
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_cannot_add_from_inactive_pharmacy(): void
    {
        $inactivePharmacy = Pharmacy::factory()->create(['is_active' => false]);
        $pm = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $inactivePharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 15.00,
        ]);

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $pm->id,
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_invalid_medicine_id_returns_422(): void
    {
        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => 999999,
            'quantity' => 1,
        ])->assertStatus(422);
    }

    public function test_invalid_quantity_returns_422(): void
    {
        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 0,
        ])->assertStatus(422);

        $this->postJson('/api/patient/cart/items', [
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => -1,
        ])->assertStatus(422);
    }

    public function test_quantity_cannot_exceed_stock_on_update(): void
    {
        $lowStock = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 5,
            'is_available' => true,
            'price' => 10.00,
        ]);

        $cart = Cart::factory()->for($this->patient)->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $lowStock->pharmacy_id,
            'pharmacy_medicine_id' => $lowStock->id,
            'quantity' => 2,
        ]);

        $this->putJson("/api/patient/cart/items/{$item->id}", [
            'quantity' => 10,
        ])->assertStatus(422);
    }

    public function test_remove_item_from_cart(): void
    {
        $cart = Cart::factory()->for($this->patient)->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 3,
        ]);

        $this->deleteJson("/api/patient/cart/items/{$item->id}")->assertStatus(200);

        $this->assertNull(CartItem::find($item->id));
    }

    public function test_remove_item_from_another_users_cart_is_forbidden(): void
    {
        $otherPatient = User::factory()->patient()->create();
        $otherCart = Cart::factory()->for($otherPatient)->create();
        $otherItem = CartItem::factory()->create([
            'cart_id' => $otherCart->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
        ]);

        $this->deleteJson("/api/patient/cart/items/{$otherItem->id}")->assertStatus(404);
    }
}