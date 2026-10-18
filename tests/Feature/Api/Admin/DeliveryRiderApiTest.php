<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Rider Delivery — إدارة المندوبين من لوحة الأدمن.
 */
class DeliveryRiderApiTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    public function test_admin_can_create_rider(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $resp = $this->postJson('/api/admin/delivery-riders', [
            'name' => 'مندوب جديد',
            'phone' => '0591112233',
            'email' => 'rider-new@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertStatus(201);
        $this->assertTrue($resp->json('data.rider.is_active'));

        $user = User::findOrFail($resp->json('data.rider.id'));
        $this->assertSame('rider', $user->role);
        $this->assertTrue($user->hasRole('rider'));
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_created_rider_can_log_in(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/admin/delivery-riders', [
            'name' => 'مندوب دخول',
            'phone' => '0591112200',
            'email' => 'rider-login@example.com',
            'password' => 'secret123',
        ])->assertStatus(201);

        $resp = $this->postJson('/api/login/rider', [
            'email' => 'rider-login@example.com',
            'password' => 'secret123',
        ]);

        $resp->assertStatus(200);
        $this->assertNotEmpty($resp->json('data.token'));
        $this->assertSame('rider', $resp->json('data.user.role'));
    }

    public function test_non_admin_cannot_manage_riders(): void
    {
        $this->actingAs($this->rider, 'sanctum');

        $this->getJson('/api/admin/delivery-riders')->assertStatus(403);
        $this->postJson('/api/admin/delivery-riders', [
            'name' => 'x', 'phone' => '0599999999', 'password' => 'secret123',
        ])->assertStatus(403);
    }

    public function test_index_lists_only_riders_with_counts(): void
    {
        User::factory()->rider()->create(); // third rider
        $this->actingAs($this->admin, 'sanctum');

        $resp = $this->getJson('/api/admin/delivery-riders')->assertStatus(200);

        $this->assertSame(3, $resp->json('data.counts.total'));
        $this->assertSame(3, $resp->json('data.counts.active'));
        $this->assertCount(3, $resp->json('data.riders'));

        // Never leaks non-rider users.
        $riders = collect($resp->json('data.riders'));
        $this->assertTrue($riders->every(fn ($r) => User::find($r['id'])->role === 'rider'));
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/admin/delivery-riders', [
            'name' => 'dup', 'phone' => $this->rider->phone, 'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_update_rider_details(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/admin/delivery-riders/{$this->rider->id}", [
            'name' => 'اسم محدّث',
            'phone' => '0598887766',
            'email' => 'updated@example.com',
            'status' => true,
        ])->assertStatus(200);

        $fresh = $this->rider->fresh();
        $this->assertSame('اسم محدّث', $fresh->name);
        $this->assertSame('0598887766', $fresh->phone);
    }

    public function test_toggle_status_disables_and_enables(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $resp = $this->patchJson("/api/admin/delivery-riders/{$this->rider->id}/toggle-status")->assertStatus(200);
        $this->assertFalse($resp->json('data.is_active'));
        $this->assertFalse($this->rider->fresh()->is_active);

        $resp2 = $this->patchJson("/api/admin/delivery-riders/{$this->rider->id}/toggle-status")->assertStatus(200);
        $this->assertTrue($resp2->json('data.is_active'));
    }

    public function test_cannot_deactivate_rider_with_active_delivery(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);
        $this->riderPickupAll($order);
        $this->assertSame(Order::STATUS_PICKED_UP, $order->refresh()->status);

        $this->actingAs($this->admin, 'sanctum');
        $this->patchJson("/api/admin/delivery-riders/{$this->rider->id}/toggle-status")
            ->assertStatus(422);
        $this->assertTrue($this->rider->fresh()->is_active);
    }

    public function test_deactivating_rider_unassigns_not_started_orders(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->admin, 'sanctum');
        $resp = $this->patchJson("/api/admin/delivery-riders/{$this->rider->id}/toggle-status")->assertStatus(200);

        $this->assertSame(1, $resp->json('data.unassigned_orders'));
        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_delete_rider_clears_assignment_and_removes_user(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $this->actingAs($this->admin, 'sanctum');
        $this->deleteJson("/api/admin/delivery-riders/{$this->rider->id}")->assertStatus(200);

        $this->assertNull($order->fresh()->rider_id);
        $this->assertDatabaseMissing('users', ['id' => $this->rider->id]);
    }
}
