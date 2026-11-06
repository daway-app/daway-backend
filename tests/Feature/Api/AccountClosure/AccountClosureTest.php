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

    private function withPatientToken(User $patient): static
    {
        $token = $patient->createToken('closure-test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
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

        $this->withPatientToken($patient)
            ->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'closure_blocked')
            ->assertJsonPath('data.blockers.0.key', 'active_orders');

        $this->assertNull($patient->fresh()->closed_at);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_explicit_confirmation_is_required(): void
    {
        $patient = $this->patient();
        foreach ([[], ['confirm' => 'delete']] as $payload) {
            $this->withPatientToken($patient)->postJson('/api/account/closure', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        }
        $this->assertNull($patient->fresh()->closed_at);
    }

    public function test_closure_requires_authentication_and_patient_role(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/account/closure', ['confirm' => 'DELETE'])->assertUnauthorized();
        $this->actingAs($this->pharmacyA->user, 'sanctum')
            ->postJson('/api/account/closure', ['confirm' => 'DELETE'])->assertForbidden();
    }

    public function test_web_session_without_a_sanctum_token_cannot_close_an_account(): void
    {
        $patient = $this->patient();
        $this->app['auth']->forgetGuards();
        $this->actingAs($patient, 'web');
        $this->postJson('/api/account/closure', ['confirm' => 'DELETE'])->assertUnauthorized();
        $this->assertNull($patient->fresh()->closed_at);
    }

    public function test_patient_can_close_with_a_bearer_token_without_password_or_otp(): void
    {
        $patient = $this->patient();
        $token = $patient->createToken('mobile')->plainTextToken;
        $other = $patient->createToken('other')->accessToken->id;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/account/status')->assertUnauthorized();
    }

    public function test_closure_removes_preexisting_login_otp_for_the_old_phone(): void
    {
        $patient = $this->patient();
        $phone = $patient->phone;
        DB::table('otp_codes')->insert([
            'phone' => $phone, 'otp' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withPatientToken($patient)->postJson('/api/account/closure', ['confirm' => 'DELETE'])->assertOk();
        $this->assertDatabaseMissing('otp_codes', ['phone' => $phone]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => '123456'])->assertStatus(400);
        $this->assertFalse((bool) $patient->fresh()->is_active);
        $this->assertSame(0, $patient->tokens()->count());
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

        $patient->forceFill([
            'address' => 'private address', 'birth_date' => '1990-01-01',
            'emergency_contact' => 'private contact', 'latitude' => 31.5, 'longitude' => 35.1,
            'remember_token' => 'private-token',
        ])->save();

        $tokenId = $patient->createToken('test')->accessToken->id;

        $this->withPatientToken($patient)
            ->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $fresh = $patient->fresh();
        $this->assertNotNull($fresh->closed_at);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertStringContainsString('حساب محذوف', $fresh->name);
        $this->assertStringNotContainsString('@example.test', (string) $fresh->email);
        $this->assertNull($fresh->phone);
        foreach (['address', 'birth_date', 'emergency_contact', 'latitude', 'longitude', 'remember_token', 'phone_verified_at', 'email_verified_at'] as $field) {
            $this->assertNull($fresh->{$field}, $field);
        }

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

        $this->withPatientToken($patient)
            ->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/account/status')
            ->assertUnauthorized();
    }

    public function test_pending_payment_submission_blocks_closure_for_a_finished_order(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);
        $order->update(['status' => 'delivered']);
        $id = DB::table('order_payment_submissions')->insertGetId([
            'order_id' => $order->id, 'bank_name' => 'Bank', 'account_holder' => 'Holder',
            'account_number' => '123', 'amount' => 42, 'sender_name' => 'Patient Name',
            'receipt_path' => 'receipt.jpg', 'receipt_original_name' => 'receipt.jpg',
            'receipt_mime' => 'image/jpeg', 'status' => 'under_review',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withPatientToken($patient)->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertStatus(409)->assertJsonPath('data.blockers.0.key', 'pending_payments');
        $this->assertDatabaseHas('order_payment_submissions', ['id' => $id, 'sender_name' => 'Patient Name']);
        $this->assertNull($patient->fresh()->closed_at);
    }

    public function test_open_payment_problem_blocks_closure_for_a_finished_order(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);
        $order->update(['status' => 'delivered']);
        $id = DB::table('payment_problems')->insertGetId([
            'order_id' => $order->id, 'category' => 'other', 'message' => 'Needs review',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withPatientToken($patient)->postJson('/api/account/closure', ['confirm' => 'DELETE'])
            ->assertStatus(409)->assertJsonPath('data.blockers.0.key', 'open_payment_problems');
        $this->assertDatabaseHas('payment_problems', ['id' => $id, 'status' => 'open']);
        $this->assertNull($patient->fresh()->closed_at);
    }

    public function test_closure_keeps_approved_payment_and_anonymizes_its_sender(): void
    {
        $patient = $this->patient();
        $order = $this->orderFor($patient);
        $order->update(['status' => 'delivered']);
        $id = DB::table('order_payment_submissions')->insertGetId([
            'order_id' => $order->id, 'bank_name' => 'Bank', 'account_holder' => 'Holder',
            'account_number' => '123', 'amount' => 42, 'sender_name' => 'Patient Name',
            'receipt_path' => 'receipt.jpg', 'receipt_original_name' => 'receipt.jpg',
            'receipt_mime' => 'image/jpeg', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withPatientToken($patient)->postJson('/api/account/closure', ['confirm' => 'DELETE'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'user_id' => $patient->id]);
        $this->assertDatabaseHas('order_payment_submissions', ['id' => $id, 'order_id' => $order->id, 'status' => 'approved', 'sender_name' => null]);
        $this->assertEquals(42, DB::table('order_payment_submissions')->where('id', $id)->value('amount'));
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

    public function test_a_closed_pharmacy_receives_no_new_orders(): void
    {
        // صيدلية مغلقة لا تُقبل لها طلبات جديدة حتى لو كانت السلة قديمة.
        $this->pharmacyA->forceFill(['is_active' => false, 'closed_at' => now()])->save();

        $this->actingAs($this->patient, 'sanctum');
        $this->seedTwoPharmacyCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'closed-'.uniqid(),
        ])->assertStatus(422);

        $this->assertSame(0, Order::query()->where('user_id', $this->patient->id)->count());
    }
    public function test_reopening_only_restores_an_owner_who_was_active_before_closure(): void
    {
        foreach ([true, false] as $wasActive) {
            $pharmacy = Pharmacy::factory()->create();
            $owner = $pharmacy->user;
            $owner->forceFill(['is_active' => $wasActive])->save();
            $token = $owner->createToken('closure-test')->accessToken;
            $closure = AccountClosureRequest::create([
                'subject_type' => Pharmacy::class, 'subject_id' => $pharmacy->id,
                'status' => AccountClosureRequest::STATUS_PENDING,
            ]);
            $service = app(\App\Services\AccountClosure\AccountClosureService::class);
            $service->approvePharmacyClosure($closure, $this->admin);
            $this->assertFalse((bool) $owner->fresh()->is_active);
            $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
            $service->reopenPharmacy($pharmacy);
            $this->assertSame($wasActive, (bool) $owner->fresh()->is_active);
            $this->assertTrue((bool) $pharmacy->fresh()->is_active);
        }
    }

    public function test_admin_cannot_reactivate_a_closed_patient_through_general_user_endpoints(): void
    {
        $patient = $this->patient();
        $patient->forceFill(['is_active' => false, 'closed_at' => now()])->save();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/admin/users/'.$patient->id.'/toggle-status')->assertStatus(409);
        $this->assertFalse((bool) $patient->fresh()->is_active);
    }

    public function test_admin_cannot_reactivate_a_closed_pharmacy_through_the_general_toggle(): void
    {
        $pharmacy = Pharmacy::factory()->create();
        $pharmacy->forceFill(['is_active' => false, 'closed_at' => now()])->save();
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/toggle-status')->assertStatus(409);
        $this->assertFalse((bool) $pharmacy->fresh()->is_active);
    }

    public function test_unbatched_settlement_adjustment_blocks_pharmacy_closure(): void
    {
        $pharmacy = Pharmacy::factory()->create();
        DB::table('platform_settlement_adjustments')->insert([
            'pharmacy_id' => $pharmacy->id, 'pharmacy_name' => $pharmacy->pharmacy_name,
            'effective_period_start' => now()->toDateString(), 'amount' => -10,
            'reason' => 'pending adjustment', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $closure = AccountClosureRequest::create([
            'subject_type' => Pharmacy::class, 'subject_id' => $pharmacy->id,
            'status' => AccountClosureRequest::STATUS_PENDING,
        ]);
        $service = app(\App\Services\AccountClosure\AccountClosureService::class);
        $this->assertContains('unbatched_adjustments', array_column($service->pharmacyBlockers($pharmacy), 'key'));
        try {
            $service->approvePharmacyClosure($closure, $this->admin);
            $this->fail('Closure must be blocked while an adjustment remains unbatched.');
        } catch (\App\Services\AccountClosure\AccountClosureBlocked $e) {
            $this->assertNull($pharmacy->fresh()->closed_at);
        }
    }

}
