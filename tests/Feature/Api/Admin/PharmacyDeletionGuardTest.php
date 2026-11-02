<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Regression: `DELETE /api/admin/pharmacies/{pharmacy}` used to hard-delete the
 * pharmacy and its user with no guard. `pharmacies` is the root of 98 cascade
 * foreign keys, so a single call destroyed sub_orders, sales, purchases,
 * expenses, cash movements, refunds and settlement history.
 */
class PharmacyDeletionGuardTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
    }

    public function test_deleting_a_pharmacy_that_owns_orders_and_sales_is_refused(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $subOrder = $this->subOrderFor($order, $this->pharmacyA);
        $subOrder->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/admin/pharmacies/'.$this->pharmacyA->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.blockers.0.key', 'sub_orders');

        // Nothing may be destroyed by a refused request.
        $this->assertDatabaseHas('pharmacies', ['id' => $this->pharmacyA->id]);
        $this->assertDatabaseHas('users', ['id' => $this->pharmacyA->user_id]);
        $this->assertDatabaseHas('sub_orders', ['id' => $subOrder->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_settlement_alone_is_enough_to_refuse_deletion(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);

        $this->actingAs($this->admin, 'sanctum');
        $settlementId = $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated()->json('data.id');

        $response = $this->deleteJson('/api/admin/pharmacies/'.$this->pharmacyA->id)->assertStatus(409);
        $keys = array_column($response->json('data.blockers'), 'key');
        $this->assertContains('platform_settlements', $keys);

        $this->assertDatabaseHas('platform_settlements', ['id' => $settlementId]);
        $this->assertDatabaseHas('pharmacies', ['id' => $this->pharmacyA->id]);
    }

    public function test_a_pharmacy_without_any_activity_can_still_be_deleted(): void
    {
        $user = User::factory()->pharmacy()->create(['password' => Hash::make('Secret-Pass-123')]);
        $pharmacy = new Pharmacy;
        $pharmacy->pharmacy_name = 'صيدلية بلا نشاط';
        $pharmacy->region = 'غزة';
        $pharmacy->phone_number = '0599000001';
        $pharmacy->user_id = $user->id;
        $pharmacy->pharmacy_custom_id = 'PH-TEST-EMPTY';
        $pharmacy->is_active = false;
        $pharmacy->save();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/admin/pharmacies/'.$pharmacy->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('pharmacies', ['id' => $pharmacy->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_guard_reports_every_blocking_table_not_just_the_first(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated();

        $keys = array_column(
            $this->deleteJson('/api/admin/pharmacies/'.$this->pharmacyA->id)->assertStatus(409)->json('data.blockers'),
            'key'
        );

        $this->assertContains('sub_orders', $keys);
        $this->assertContains('platform_settlements', $keys);
    }
}
