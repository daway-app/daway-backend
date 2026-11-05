<?php

namespace Tests\Feature\Api\AccountClosure;

use App\Models\AccountClosureRequest;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * إغلاق حسابات المرضى والصيدليات.
 *
 * يثبت: إخفاء الهوية في كل مكان (لا الاسم فقط)، إبطال التوكنات، منع الدخول،
 * حجب الإغلاق عند الالتزامات، وأن **صفر سجل مالي** يُحذف.
 */
class AccountClosureTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
    }

    /** مريض المساعد هو صاحب الطلب فعليًا — نضبط كلمة مروره فقط. */
    private function patient(): User
    {
        $this->patient->forceFill([
            'password' => Hash::make('Secret-123'),
            'phone' => '0599000111',
            'is_active' => true,
        ])->save();

        return $this->patient->fresh();
    }

    private function orderFor(User $patient): Order
    {
        $this->actingAs($patient, 'sanctum');
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->actingAs($this->admin, 'sanctum');

        return $order->fresh();
    }

    // ---------------- المريض ----------------

    public function test_a_patient_with_an_active_order_cannot_close_the_account(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);

        $this->actingAs($patient, 'sanctum')
            ->postJson('/api/account/closure', ['password' => 'Secret-123', 'confirm' => 'DELETE'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'closure_blocked')
            ->assertJsonPath('data.blockers.0.key', 'active_orders');

        $this->assertNull($patient->fresh()->closed_at);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient, 'sanctum')
            ->postJson('/api/account/closure', ['password' => 'wrong', 'confirm' => 'DELETE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_password');

        $this->assertNull($patient->fresh()->closed_at);
    }

    public function test_closing_an_account_anonymises_everywhere_and_keeps_financial_rows(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);
        // نُنهي الطلب كي لا يمنع الإغلاق.
        Order::where('id', $order->id)->update(['status' => 'delivered']);
        SubOrder::where('order_id', $order->id)->update(['status' => SubOrder::STATUS_DELIVERED]);

        DB::table('addresses')->insert([
            'user_id' => $patient->id,
            'recipient_name' => 'اسم شخصي',
            'phone' => '0599888777',
            'address' => 'شارع شخصي 12',
            'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::where('id', $order->id)->update(['notes' => 'ملاحظة شخصية حساسة']);

        $tokenId = $patient->createToken('test')->accessToken->id;

        $this->actingAs($patient, 'sanctum')
            ->postJson('/api/account/closure', ['password' => 'Secret-123', 'confirm' => 'DELETE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $fresh = $patient->fresh();
        $this->assertNotNull($fresh->closed_at);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertStringContainsString('حساب محذوف', $fresh->name);
        $this->assertStringNotContainsString('@example.test', (string) $fresh->email);
        $this->assertNull($fresh->phone);

        // العنوان أُخفي في مكانه (لم يُحذف — حذفه يمحو الطلب بالـcascade).
        $address = DB::table('addresses')->where('user_id', $patient->id)->first();
        $this->assertNotNull($address);
        $this->assertNull($address->phone);
        $this->assertSame('—', $address->address);

        // ملاحظات الطلب أُخفيَت، والطلب نفسه باقٍ.
        $this->assertNull(Order::find($order->id)->notes);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        // التوكنات أُبطلت.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_the_previous_token_stops_working_after_closure(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);
        Order::where('id', $order->id)->update(['status' => 'delivered']);
        SubOrder::where('order_id', $order->id)->update(['status' => SubOrder::STATUS_DELIVERED]);

        $token = $patient->createToken('mobile')->plainTextToken;

        $this->actingAs($patient, 'sanctum')
            ->postJson('/api/account/closure', ['password' => 'Secret-123', 'confirm' => 'DELETE'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/account/status')
            ->assertUnauthorized();
    }

    // ---------------- الصيدلية ----------------

    public function test_only_the_owner_can_request_pharmacy_closure(): void
    {
        $employee = User::factory()->create();
        $employee->syncRoles(['pharmacy']);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', ['reason' => 'test'])
            ->assertStatus(403); // يحجبها middleware role:pharmacy قبل المتحكم
    }

    public function test_a_pharmacy_may_request_closure_even_with_outstanding_dues(): void
    {
        $this->orderFor($this->patient());

        $response = $this->actingAs($this->pharmacyA->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', ['reason' => 'إغلاق نهائي'])
            ->assertStatus(201);

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNotEmpty($response->json('data.blockers'));
    }

    public function test_admin_cannot_approve_while_dues_remain(): void
    {
        $this->orderFor($this->patient());

        $this->actingAs($this->pharmacyA->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', [])
            ->assertStatus(201);

        $closure = AccountClosureRequest::where('subject_id', $this->pharmacyA->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/account-closures/'.$closure->id.'/approve', [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'closure_blocked');

        $this->assertNull($this->pharmacyA->fresh()->closed_at);
    }

    public function test_admin_can_approve_a_clean_pharmacy_and_it_disappears_from_search(): void
    {
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        $this->actingAs($pharmacy->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', [])
            ->assertStatus(201);

        $closure = AccountClosureRequest::where('subject_id', $pharmacy->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/account-closures/'.$closure->id.'/approve', ['note' => 'ok'])
            ->assertOk();

        $fresh = $pharmacy->fresh();
        $this->assertNotNull($fresh->closed_at);
        $this->assertFalse((bool) $fresh->is_active);
        // الاسم يبقى للفواتير التاريخية (D-4).
        $this->assertSame($pharmacy->pharmacy_name, $fresh->pharmacy_name);

        $this->app['auth']->forgetGuards();
        $visible = collect($this->getJson('/api/pharmacies')->json('data'))->pluck('id');
        $this->assertNotContains($pharmacy->id, $visible);
    }

    public function test_admin_can_reject_a_closure_request(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $this->actingAs($pharmacy->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', [])
            ->assertStatus(201);

        $closure = AccountClosureRequest::where('subject_id', $pharmacy->id)->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/account-closures/'.$closure->id.'/reject', ['note' => 'بيانات ناقصة'])
            ->assertOk();

        $this->assertSame('rejected', $closure->fresh()->status);
        $this->assertNull($pharmacy->fresh()->closed_at);
    }

    public function test_a_duplicate_request_returns_the_existing_one(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $first = $this->actingAs($pharmacy->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', [])
            ->assertStatus(201)
            ->json('data.id');

        $second = $this->actingAs($pharmacy->user, 'sanctum')
            ->postJson('/api/pharmacy/account/closure-request', [])
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AccountClosureRequest::where('subject_id', $pharmacy->id)->count());
    }
}
