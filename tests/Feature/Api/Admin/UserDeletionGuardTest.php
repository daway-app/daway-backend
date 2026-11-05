<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Models\User;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * حارس حذف حساب المستخدم.
 *
 * الخطر الذي يمنعه: `users → orders` و`users → pharmacies` كلاهما cascade،
 * فحذف مستخدم واحد كان يمحو طلباته، أو تاريخ صيدلية كاملة إن كان مالكها.
 */
class UserDeletionGuardTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function deliveredPaidOrder(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);

        // checkout يعيد تسجيل الدخول كعميل — نعود للأدمن قبل استدعاء مسار الحذف.
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_a_user_with_orders_cannot_be_deleted(): void
    {
        $this->deliveredPaidOrder();
        $customer = $this->pharmacyA->user; // placeholder, replaced below
        $order = \App\Models\Order::query()->latest('id')->firstOrFail();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$order->user_id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNotEmpty($response->json('data.blockers'));
        $this->assertDatabaseHas('users', ['id' => $order->user_id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_pharmacy_owner_cannot_be_deleted_while_the_pharmacy_has_records(): void
    {
        $this->deliveredPaidOrder();
        $owner = $this->pharmacyA->user;

        $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$owner->id)
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('pharmacies', ['id' => $this->pharmacyA->id]);
        $this->assertGreaterThan(0, SubOrder::where('pharmacy_id', $this->pharmacyA->id)->count());
    }

    public function test_a_clean_user_without_records_is_deleted(): void
    {
        $clean = User::factory()->create(['name' => 'حساب نظيف']);

        $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$clean->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $clean->id]);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$this->admin->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_an_owner_of_an_empty_pharmacy_can_still_be_deleted(): void
    {
        // صيدلية بلا أي سجل مالي: الحذف مسموح (نفس سياسة PharmacyDeletionGuard).
        $pharmacy = Pharmacy::factory()->create();

        $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$pharmacy->user_id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $pharmacy->user_id]);
        $this->assertDatabaseMissing('pharmacies', ['id' => $pharmacy->id]);
    }
}
