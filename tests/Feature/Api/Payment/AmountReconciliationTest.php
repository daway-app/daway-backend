<?php

namespace Tests\Feature\Api\Payment;

use App\Models\Order;
use App\Models\OrderPaymentSubmission;
use App\Models\PaymentBank;
use App\Models\PaymentProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class AmountReconciliationTest extends TestCase
{
    use BuildsDeliveryOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bootDeliveryWorld();
    }

    private function bank(): PaymentBank
    {
        return PaymentBank::create([
            'bank_name' => 'بنك الاختبار',
            'account_holder' => 'دواوي',
            'account_number' => '0123456789',
            'iban' => 'PS92PALS0000000001234567890',
            'branch' => 'رام الله',
            'instructions' => 'أرسل الإيصال.',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function submit(Order $order, PaymentBank $bank, float $amount): int
    {
        $this->actingAs($this->patient, 'sanctum');

        return $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $bank->id,
            'amount' => $amount,
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 1200)->size(200),
            'sender_name' => 'المريض',
            'transfer_reference' => 'TRX-'.uniqid(),
            'transferred_at' => now()->toDateString(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('data.submission.id');
    }

    private function approve(int $orderId): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->admin, 'sanctum');

        return $this->postJson("/api/admin/payments/{$orderId}/approve");
    }

    public function test_matching_amount_is_approved(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();

        $this->submit($order, $bank, (float) $order->total);

        $this->approve($order->id)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->payment_reviewed_by);

        // Submission stores the snapshot and the matching status.
        $submission = OrderPaymentSubmission::find($this->approve($order->id)->json('data.submission.id') ?? 0)
            ?? OrderPaymentSubmission::where('order_id', $order->id)->first();

        $this->assertSame('matches', $submission->amount_status);
        $this->assertEqualsWithDelta((float) $order->total, (float) $submission->order_grand_total, 0.001);
    }

    public function test_under_amount_is_blocked_with_deficit(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $this->submit($order, $bank, (float) $order->total - 50);

        $resp = $this->approve($order->id)->assertStatus(422);

        $this->assertSame('amount_unmatched', $resp->json('data.code'));
        $this->assertEqualsWithDelta(50.0, (float) $resp->json('data.deficit'), 0.001);
        $this->assertEqualsWithDelta((float) $order->total, (float) $resp->json('data.grand_total'), 0.001);

        $this->assertSame(Order::PAYMENT_UNDER_REVIEW, $order->fresh()->payment_status);
        $this->assertSame(OrderPaymentSubmission::STATUS_UNDER_REVIEW, $order->fresh()->latestPaymentSubmission->status);
        $this->assertNull($order->fresh()->payment_reviewed_by, 'no approval stamp');
    }

    public function test_over_amount_is_blocked_with_overage_and_not_auto_refunded(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $this->submit($order, $bank, (float) $order->total + 30);

        $resp = $this->approve($order->id)->assertStatus(422);

        $this->assertSame('amount_unmatched', $resp->json('data.code'));
        $this->assertEqualsWithDelta(30.0, (float) $resp->json('data.overage'), 0.001);

        $submission = $order->fresh()->latestPaymentSubmission;
        $this->assertSame('over', $submission->amount_status);
        $this->assertSame(30.0, (float) $submission->overage_amount);
        $this->assertSame(Order::PAYMENT_UNDER_REVIEW, $order->fresh()->payment_status, 'not auto-marked paid');
    }

    public function test_order_total_changed_during_review_blocks_approval(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $this->submit($order, $bank, (float) $order->total);

        // Order total drifts after the snapshot was taken.
        $order->update(['delivery_fee' => 20]);

        $resp = $this->approve($order->id)->assertStatus(422);

        $this->assertSame('amount_unmatched', $resp->json('data.code'));
        $this->assertEqualsWithDelta((float) $order->total + 20, (float) $resp->json('data.grand_total'), 0.001);

        $this->assertSame(Order::PAYMENT_UNDER_REVIEW, $order->fresh()->payment_status);
    }

    public function test_resubmit_after_rejection_then_approve(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();

        $this->submit($order, $bank, (float) $order->total - 10);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/payments/{$order->id}/reject", ['reason' => 'المبلغ ناقص']);

        $this->assertSame(Order::PAYMENT_REJECTED, $order->fresh()->payment_status);

        $this->submit($order, $bank, (float) $order->total);

        $this->approve($order->id)
            ->assertStatus(200)
            ->assertJsonPath('data.payment_status', Order::PAYMENT_PAID);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_repeat_approve_is_idempotent(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $this->submit($order, $bank, (float) $order->total);

        $this->approve($order->id)->assertStatus(200);
        $second = $this->approve($order->id);
        $second->assertStatus(200)->assertJsonPath('data.already_paid', true);

        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
        $this->assertSame(1, OrderPaymentSubmission::where('order_id', $order->id)->where('status', 'approved')->count());
    }

    public function test_open_payment_problem_blocks_approval(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $this->submit($order, $bank, (float) $order->total);

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/payment/problem", [
            'category' => PaymentProblem::CATEGORY_AMOUNT,
            'message' => 'صعوبة التحويل.',
        ])->assertCreated();

        $this->approve($order->id)
            ->assertStatus(422)
            ->assertJsonMissingPath('data.code'); // blocked by existing open-problem rule
    }

    public function test_failed_approval_leaves_state_untouched(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $bank = $this->bank();
        $submissionId = $this->submit($order, $bank, (float) $order->total - 25);

        $submission = OrderPaymentSubmission::findOrFail($submissionId);
        $statusBefore = $submission->status;
        $reviewedBefore = $submission->reviewed_at;

        $this->approve($order->id)->assertStatus(422);

        $fresh = $submission->fresh();
        $this->assertSame($statusBefore, $fresh->status, 'submission stays under_review');
        $this->assertNull($fresh->reviewed_at, 'no approval stamp');
        $this->assertNull($fresh->reviewed_by);
        $this->assertSame(Order::PAYMENT_UNDER_REVIEW, $order->fresh()->payment_status);
    }

    public function test_phase1_payment_gate_remains_intact(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $subA = $this->subOrderFor($order, $this->pharmacyA);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->postJson("/api/pharmacy/sub-orders/{$subA->id}/status", [
            'status' => \App\Models\SubOrder::STATUS_PREPARING,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.code', 'payment_required');
    }
}
