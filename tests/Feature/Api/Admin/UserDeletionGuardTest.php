<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * حارس حذف حساب المستخدم.
 *
 * الخطر الذي يمنعه: `users → orders` و`users → pharmacies` كلاهما cascade،
 * فحذف مستخدم واحد كان يمحو طلباته، أو تاريخ صيدلية كاملة إن كان مالكها.
 *
 * الاختبارات تبني الحالة صراحةً (صف طلب بـuser_id معروف) بدل الاعتماد على
 * تفاصيل مساعد الـcheckout — كي لا يعتمد الحارس على سلوك متغيّر.
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

    /** طلب مكتمل لعميل محدّد، مع صف طلب مؤكد. */
    private function orderForCustomer(): Order
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);

        // نعود للأدمن: checkout يسجّل الدخول كعميل.
        $this->actingAs($this->admin, 'sanctum');

        return $order->fresh();
    }

    public function test_a_user_with_orders_cannot_be_deleted(): void
    {
        $order = $this->orderForCustomer();

        // شرط مسبق صريح: للعميل صف طلب واحد على الأقل.
        $this->assertNotNull($order->user_id);
        $this->assertSame(1, DB::table('orders')->where('user_id', $order->user_id)->count());

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$order->user_id)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNotEmpty($response->json('data.blockers'));
        $this->assertSame('orders', $response->json('data.blockers.0.key'));

        $this->assertDatabaseHas('users', ['id' => $order->user_id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_pharmacy_owner_cannot_be_deleted_while_the_pharmacy_has_records(): void
    {
        $this->orderForCustomer();
        $owner = $this->pharmacyA->user;

        $this->assertGreaterThan(0, SubOrder::where('pharmacy_id', $this->pharmacyA->id)->count());

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->deleteJson('/api/admin/users/'.$owner->id)
            ->assertStatus(409);

        $keys = array_column($response->json('data.blockers'), 'key');
        $this->assertContains('pharmacy', $keys);

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('pharmacies', ['id' => $this->pharmacyA->id]);
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
