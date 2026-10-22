<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderPaymentSubmission;
use App\Models\PaymentBank;
use App\Models\PaymentProblem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class PaymentPhaseApiTest extends TestCase
{
    use BuildsDeliveryOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->bootDeliveryWorld();
    }

    public function test_patient_sees_only_active_banks_and_grand_total_includes_delivery(): void
    {
        $this->bank(['bank_name' => 'بنك فلسطين', 'is_active' => true]);
        $this->bank(['bank_name' => 'بنك الإسكان', 'is_active' => false]);

        $order = $this->checkoutTwoPharmacy();
        $order->update(['delivery_fee' => 10]);

        $this->getJson('/api/patient/payment-banks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bank_name', 'بنك فلسطين');

        $payment = $this->getJson("/api/patient/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PENDING)
            ->assertJsonPath('data.can_submit_receipt', true)
            ->assertJsonPath('data.can_report_problem', true);

        $this->assertEquals((float) $order->total + 10, $payment->json('data.grand_total'));
    }

    public function test_admin_cannot_have_more_than_three_active_banks_or_remove_the_last_one(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/admin/payment-banks', $this->bankData([
                'bank_name' => "بنك {$i}",
                'is_active' => true,
            ]))->assertCreated();
        }

        $this->postJson('/api/admin/payment-banks', $this->bankData([
            'bank_name' => 'بنك رابع',
            'is_active' => true,
        ]))->assertStatus(422);

        $banks = PaymentBank::query()->orderBy('id')->get();
        $this->patchJson("/api/admin/payment-banks/{$banks[0]->id}/toggle-status")->assertOk();
        $this->patchJson("/api/admin/payment-banks/{$banks[1]->id}/toggle-status")->assertOk();
        $this->patchJson("/api/admin/payment-banks/{$banks[2]->id}/toggle-status")->assertStatus(422);
        $this->deleteJson("/api/admin/payment-banks/{$banks[2]->id}")->assertStatus(422);

        $this->assertSame(1, PaymentBank::query()->active()->count());
    }

    public function test_patient_uploads_receipt_and_admin_approves_idempotently(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();

        $response = $this->submitReceipt($order, $bank)
            ->assertCreated()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_UNDER_REVIEW)
            ->assertJsonPath('data.submission.status', OrderPaymentSubmission::STATUS_UNDER_REVIEW);

        $submission = OrderPaymentSubmission::query()->firstOrFail();
        Storage::disk('local')->assertExists($submission->receipt_path);
        $this->assertSame(Order::PAYMENT_UNDER_REVIEW, $order->fresh()->payment_status);
        $this->assertSame(
            Order::PAYMENT_UNDER_REVIEW,
            $order->subOrders()->firstOrFail()->payment_status
        );

        $this->submitReceipt($order, $bank)->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson("/api/admin/payments/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.can_approve', true)
            ->assertJsonPath('data.payment.can_reject', true)
            ->assertJsonCount(1, 'data.submissions');

        $this->postJson("/api/admin/payments/{$order->id}/approve", [
            'review_note' => 'تم مطابقة التحويل.',
        ])->assertOk()->assertJsonPath('data.already_paid', false);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertSame(OrderPaymentSubmission::STATUS_APPROVED, $submission->fresh()->status);
        $this->assertSame(Order::PAYMENT_PAID, $order->subOrders()->firstOrFail()->payment_status);

        $this->postJson("/api/admin/payments/{$order->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.already_paid', true);

        $this->postJson("/api/admin/payments/{$order->id}/reject", [
            'reason' => 'متأخر',
        ])->assertStatus(422);
    }

    public function test_rejection_allows_resubmission_and_requires_a_reason(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();

        $this->submitReceipt($order, $bank)->assertCreated();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/payments/{$order->id}/reject", [])->assertStatus(422);

        $this->postJson("/api/admin/payments/{$order->id}/reject", [
            'reason' => 'اسم المحوّل غير مطابق.',
        ])->assertOk()->assertJsonPath('data.payment_status', Order::PAYMENT_REJECTED);

        $this->assertSame(Order::PAYMENT_REJECTED, $order->fresh()->payment_status);

        $this->actingAs($this->patient, 'sanctum');
        $this->getJson("/api/patient/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.can_submit_receipt', true)
            ->assertJsonPath('data.latest_submission.status', OrderPaymentSubmission::STATUS_REJECTED);

        $this->submitReceipt($order, $bank)
            ->assertCreated()
            ->assertJsonPath('data.payment_status', Order::PAYMENT_UNDER_REVIEW);

        $this->assertSame(2, $order->paymentSubmissions()->count());
    }

    public function test_problem_report_blocks_review_until_resolved_and_never_confirms_payment(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();

        $this->submitReceipt($order, $bank)->assertCreated();
        $submission = OrderPaymentSubmission::query()->firstOrFail();

        $this->postJson("/api/patient/orders/{$order->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_AMOUNT,
            'message' => 'المبلغ المطلوب لا يطابق إجمالي الطلب.',
            'submission_id' => $submission->id,
        ])->assertCreated()->assertJsonPath('data.payment_status', Order::PAYMENT_PROBLEM_REPORTED);

        $this->assertSame(Order::PAYMENT_PROBLEM_REPORTED, $order->fresh()->payment_status);
        $this->assertNotSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);

        $this->postJson("/api/patient/orders/{$order->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_RECEIPT,
            'message' => 'بلاغ مكرر.',
        ])->assertStatus(409);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/payments/{$order->id}/approve")->assertStatus(422);
        $this->getJson("/api/admin/payments/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.can_approve', false)
            ->assertJsonPath('data.payment.can_reject', false);

        $problem = PaymentProblem::query()->firstOrFail();
        $this->getJson('/api/admin/payment-problems?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $order->id);

        $this->postJson("/api/admin/payment-problems/{$problem->id}/resolve", [
            'admin_note' => 'تمت مراجعة المبلغ وتصحيحه.',
        ])->assertOk()->assertJsonPath('data.payment_status', Order::PAYMENT_UNDER_REVIEW);

        $this->getJson("/api/admin/payments/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.can_approve', true)
            ->assertJsonPath('data.payment.can_reject', true);

        $this->postJson("/api/admin/payments/{$order->id}/approve")->assertOk();
        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_other_patients_and_non_admins_cannot_access_payment_records(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();
        $this->submitReceipt($order, $bank)->assertCreated();
        $submission = OrderPaymentSubmission::query()->firstOrFail();

        $other = User::factory()->patient()->create();
        $this->actingAs($other, 'sanctum');

        $this->getJson("/api/patient/orders/{$order->id}/payment")->assertNotFound();
        $this->postJson("/api/patient/orders/{$order->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_OTHER,
            'message' => 'محاولة وصول.',
        ])->assertNotFound();
        $this->getJson("/api/patient/orders/{$order->id}/payment/submissions/{$submission->id}/receipt")
            ->assertNotFound();
        $this->getJson('/api/admin/payments')->assertForbidden();
    }

    public function test_receipt_validation_rejects_invalid_files_inactive_banks_and_future_dates(): void
    {
        $active = $this->bank(['is_active' => true]);
        $inactive = $this->bank(['is_active' => false, 'bank_name' => 'بنك معطل']);
        $order = $this->checkoutTwoPharmacy();

        $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $active->id,
            'amount' => 130,
            'receipt' => UploadedFile::fake()->create('receipt.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $inactive->id,
            'amount' => 130,
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $active->id,
            'amount' => 130,
            'transferred_at' => now()->addDay()->toDateString(),
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, OrderPaymentSubmission::query()->count());
        $this->assertSame(Order::PAYMENT_PENDING, $order->fresh()->payment_status);
    }

    public function test_owner_and_admin_can_download_the_private_receipt(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();
        $this->submitReceipt($order, $bank)->assertCreated();
        $submission = OrderPaymentSubmission::query()->firstOrFail();

        $this->get("/api/patient/orders/{$order->id}/payment/submissions/{$submission->id}/receipt")
            ->assertOk()
            ->assertDownload($submission->receipt_original_name);

        $this->actingAs($this->admin, 'sanctum');
        $this->get("/api/admin/payments/{$order->id}/submissions/{$submission->id}/receipt")
            ->assertOk()
            ->assertDownload($submission->receipt_original_name);
    }

    public function test_admin_payment_search_matches_only_the_user_who_owns_the_phone(): void
    {
        $this->patient->update(['phone' => '0599-111-222']);
        $firstOrder = $this->checkoutTwoPharmacy();

        $otherPatient = User::factory()->patient()->create(['phone' => '0599-999-999']);
        $otherAddress = Address::factory()->for($otherPatient)->create([
            'recipient_name' => 'مريض آخر',
            'phone' => '0599-999-999',
            'address' => 'شارع آخر',
            'latitude' => 31.5016,
            'longitude' => 34.4676,
        ]);

        $originalPatient = $this->patient;
        $originalAddress = $this->address;
        $this->patient = $otherPatient;
        $this->address = $otherAddress;
        $secondOrder = $this->checkoutTwoPharmacy();
        $this->patient = $originalPatient;
        $this->address = $originalAddress;

        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/admin/payments?q=0599-111-222')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $firstOrder->id);

        $this->getJson('/api/admin/payments?q=0599-999-999')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $secondOrder->id);
    }

    public function test_problem_submission_must_belong_to_the_same_order(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $firstOrder = $this->checkoutTwoPharmacy();
        $secondOrder = $this->checkoutTwoPharmacy();

        $this->submitReceipt($secondOrder, $bank)->assertCreated();
        $foreignSubmission = OrderPaymentSubmission::query()
            ->where('order_id', $secondOrder->id)
            ->firstOrFail();

        $this->postJson("/api/patient/orders/{$firstOrder->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_BANK_DETAILS,
            'message' => 'بيانات البنك غير واضحة.',
            'submission_id' => $foreignSubmission->id,
        ])->assertStatus(422);

        $this->assertSame(0, PaymentProblem::query()->count());
    }

    public function test_resolving_a_problem_on_a_cancelled_order_leaves_payment_fields_alone(): void
    {
        $bank = $this->bank(['is_active' => true]);
        $order = $this->checkoutTwoPharmacy();
        $this->submitReceipt($order, $bank)->assertCreated();

        $this->postJson("/api/patient/orders/{$order->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_AMOUNT,
            'message' => 'المبلغ لا يطابق التحويل.',
        ])->assertCreated();

        $problem = PaymentProblem::query()->firstOrFail();
        $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/payment-problems/{$problem->id}/resolve", [
            'admin_note' => 'تمت تسوية البلاغ مع المريض.',
        ])->assertOk()->assertJsonPath('data.payment_status', Order::PAYMENT_PROBLEM_REPORTED);

        $fresh = $order->fresh();
        $this->assertSame(Order::PAYMENT_PROBLEM_REPORTED, $fresh->payment_status);
        $this->assertNotNull($fresh->payment_problem_reported_at);
        $this->assertSame(PaymentProblem::STATUS_RESOLVED, $problem->fresh()->status);
    }

    /** @param array<string, mixed> $overrides */
    private function bank(array $overrides = []): PaymentBank
    {
        return PaymentBank::create(array_merge($this->bankData(), $overrides));
    }

    /** @return array<string, mixed> */
    private function bankData(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_merge([
            'bank_name' => "بنك اختبار {$sequence}",
            'account_holder' => 'شركة دواي',
            'account_number' => '123456789'.$sequence,
            'iban' => 'PS92PALS000000000123456789'.$sequence,
            'branch' => 'غزة',
            'instructions' => 'أرفق صورة الإيصال.',
            'is_active' => false,
            'sort_order' => $sequence,
        ], $overrides);
    }

    private function submitReceipt(Order $order, PaymentBank $bank): TestResponse
    {
        $this->actingAs($this->patient, 'sanctum');

        return $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $bank->id,
            'amount' => (float) $order->total,
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 1200)->size(200),
            'sender_name' => 'عبود',
            'transfer_reference' => 'TRX-'.uniqid(),
            'transferred_at' => now()->toDateString(),
        ], ['Accept' => 'application/json']);
    }
}
