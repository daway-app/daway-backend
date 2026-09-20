<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Coupon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patient = User::factory()->patient()->create();
        $this->actingAs($this->patient, 'sanctum');

        Coupon::query()->delete();
    }

    public function test_valid_percentage_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'TEST10',
            'type' => 'percentage',
            'value' => 10,
            'minimum_order_amount' => 50,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'TEST10',
            'order_total' => 100,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true, 'message' => 'الكوبون صالح'])
            ->assertJsonFragment(['discount' => 10.0]);
    }

    public function test_valid_fixed_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'FIXED25',
            'type' => 'fixed',
            'value' => 25,
            'minimum_order_amount' => 100,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'FIXED25',
            'order_total' => 200,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true, 'message' => 'الكوبون صالح'])
            ->assertJsonFragment(['discount' => 25.0]);
    }

    public function test_minimum_order_not_met(): void
    {
        $coupon = Coupon::create([
            'code' => 'MIN50',
            'type' => 'fixed',
            'value' => 20,
            'minimum_order_amount' => 100,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'MIN50',
            'order_total' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false])
            ->assertJsonFragment(['message' => 'الحد الأدنى للطلب هو 100.00 ريال']);
    }

    public function test_maximum_discount_applied_correctly(): void
    {
        $coupon = Coupon::create([
            'code' => 'MAX10',
            'type' => 'percentage',
            'value' => 50,
            'minimum_order_amount' => 10,
            'maximum_discount' => 10,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'MAX10',
            'order_total' => 100,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true])
            ->assertJsonFragment(['discount' => 10.0]);
    }

    public function test_coupon_not_started_yet(): void
    {
        $coupon = Coupon::create([
            'code' => 'FUTURE',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'starts_at' => Carbon::now()->addDays(2),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'FUTURE',
            'order_total' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false, 'message' => 'الكوبون غير صالح أو منتهي الصلاحية']);
    }

    public function test_expired_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'EXPIRED',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'expires_at' => Carbon::now()->subDay(),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'EXPIRED',
            'order_total' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false, 'message' => 'الكوبون غير صالح أو منتهي الصلاحية']);
    }

    public function test_usage_limit_reached(): void
    {
        $coupon = Coupon::create([
            'code' => 'LIMITED',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'usage_limit' => 2,
            'used_count' => 2,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'LIMITED',
            'order_total' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false, 'message' => 'الكوبون غير صالح أو منتهي الصلاحية']);
    }

    public function test_inactive_coupon(): void
    {
        $coupon = Coupon::create([
            'code' => 'INACTIVE',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'INACTIVE',
            'order_total' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false, 'message' => 'الكوبون غير صالح أو منتهي الصلاحية']);
    }

    public function test_user_eligibility_with_per_user_limit(): void
    {
        $coupon = Coupon::create([
            'code' => 'USERLIMIT',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'per_user_limit' => 1,
            'users_used_count' => 0,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'USERLIMIT',
            'order_total' => 50,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true, 'message' => 'الكوبون صالح']);
    }

    public function test_invalid_coupon_code(): void
    {
        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'NONEXISTENT',
            'order_total' => 100,
        ]);

        $response->assertStatus(422);
    }

    public function test_client_cannot_manipulate_discount(): void
    {
        $coupon = Coupon::create([
            'code' => 'DISCOUNTCONTROL',
            'type' => 'fixed',
            'value' => 20,
            'minimum_order_amount' => 50,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'DISCOUNTCONTROL',
            'order_total' => 100,
        ]);

        $data = $response->json('data');
        $this->assertEquals(20.0, $data['discount']);
        $this->assertEquals('fixed', $data['type']);
        $this->assertEquals(20, $data['value']);
    }

    public function test_non_patient_user_cannot_validate_coupon(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $this->actingAs($pharmacyUser, 'sanctum');

        $coupon = Coupon::create([
            'code' => 'AUTHTEST',
            'type' => 'fixed',
            'value' => 10,
            'minimum_order_amount' => 10,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/patient/coupons/validate', [
            'code' => 'AUTHTEST',
            'order_total' => 50,
        ]);

        $response->assertStatus(403);
    }

    protected function tearDown(): void
    {
        Coupon::query()->delete();
        parent::tearDown();
    }
}