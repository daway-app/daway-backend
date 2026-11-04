<?php

namespace Tests\Feature\Api\Admin;

use App\Models\PlatformSettlement;
use App\Models\PlatformSettlementAdjustment;
use App\Models\SubOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Regression for P0.2.
 *
 * Scenario from the audit: a 100 draft plus an ungrouped −20 adjustment showed
 * "80 due" to the admin, while `payPharmacy()` transferred the draft's own
 * `net_total` (100) and left −20 pending forever — so the displayed amount, the
 * transferred amount and the recorded amount disagreed, and the balance drifted
 * negative. These tests pin displayed == transferred == recorded.
 */
class SettlementAccountingTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
    }

    private function deliveredPaidOrder(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function adjust(float $amount, string $reason = 'تصحيح اختبار'): void
    {
        $this->postJson('/api/admin/platform-settlements/adjustments', [
            'pharmacy_id' => $this->pharmacyA->id,
            'effective_period_start' => '2026-10-27',
            'amount' => $amount,
            'reason' => $reason,
        ])->assertCreated();
    }

    private function dueRow(): ?array
    {
        $rows = $this->getJson('/api/admin/platform-settlements/due')->assertOk()->json('data');

        foreach ($rows as $row) {
            if ($row['pharmacy_id'] === $this->pharmacyA->id) {
                return $row;
            }
        }

        return null;
    }

    private function pay(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('transfer.png', 40, 'image/png'),
                'transfer_reference' => 'BANK-ACCT-1',
            ]);
    }

    public function test_displayed_due_equals_the_amount_actually_transferred(): void
    {
        $this->deliveredPaidOrder();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated()->assertJsonPath('data.net_total', 100);

        $this->adjust(-20, 'خصم معلّق');

        // ما يراه الأدمن قبل الدفع.
        $due = $this->dueRow();
        $this->assertNotNull($due);
        $this->assertSame(80.0, (float) $due['total_due']);
        $this->assertSame(100.0, (float) $due['drafts_total']);
        $this->assertSame(-20.0, (float) $due['adjustments_total']);

        // ما يُحوَّل فعليًا ويُسجَّل.
        $paid = $this->pay()->assertOk();
        $this->assertSame(80.0, (float) $paid->json('data.0.net_total'));
        $this->assertSame(100.0, (float) $paid->json('data.0.orders_total'));
        $this->assertSame(-20.0, (float) $paid->json('data.0.adjustments_total'));
        $this->assertSame('paid', $paid->json('data.0.status'));

        // لا يبقى شيء معلّق ولا رصيد سالب.
        $this->assertNull($this->dueRow());
        $this->assertSame(0, PlatformSettlementAdjustment::whereNull('settlement_id')->count());
        $this->assertSame(
            (float) $paid->json('data.0.net_total'),
            (float) PlatformSettlement::findOrFail($paid->json('data.0.id'))->net_total
        );
    }

    public function test_a_positive_adjustment_increases_the_transfer_by_exactly_its_value(): void
    {
        $this->deliveredPaidOrder();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated();
        $this->adjust(30, 'مكافأة');

        $this->assertSame(130.0, (float) $this->dueRow()['total_due']);
        $this->pay()->assertOk()->assertJsonPath('data.0.net_total', 130);
        $this->assertNull($this->dueRow());
    }

    public function test_accumulated_adjustments_are_folded_in_once_without_double_counting(): void
    {
        $this->deliveredPaidOrder();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated();

        $this->adjust(-20);
        $this->adjust(5);
        $this->adjust(-3);

        $this->assertSame(82.0, (float) $this->dueRow()['total_due']);

        $paid = $this->pay()->assertOk();
        $this->assertSame(82.0, (float) $paid->json('data.0.net_total'));

        $settlementId = $paid->json('data.0.id');
        $this->assertSame(3, PlatformSettlementAdjustment::where('settlement_id', $settlementId)->count());
        $this->assertSame(0, PlatformSettlementAdjustment::whereNull('settlement_id')->count());
        $this->assertSame(-18.0, (float) PlatformSettlementAdjustment::sum('amount'));
        $this->assertNull($this->dueRow());
    }

    public function test_an_adjustment_registered_after_a_draft_no_longer_blocks_the_transfer(): void
    {
        // هذا هو العرض الأصلي: كان الدفع يرجع 422 لأن التعديل السالب وحده
        // حاول إنشاء مسودة صافية سالبة بدل أن يُطوى في المسودة القائمة.
        $this->deliveredPaidOrder();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated();
        $this->adjust(-20);

        $this->pay()->assertOk()->assertJsonPath('data.0.net_total', 80);
    }

    public function test_a_second_payment_attempt_is_refused_and_changes_nothing(): void
    {
        $this->deliveredPaidOrder();
        $first = $this->pay()->assertOk();
        $paidCount = PlatformSettlement::where('status', PlatformSettlement::STATUS_PAID)->count();

        $this->pay()->assertStatus(422);

        $this->assertSame($paidCount, PlatformSettlement::where('status', PlatformSettlement::STATUS_PAID)->count());
        $this->assertSame(
            (float) $first->json('data.0.net_total'),
            (float) PlatformSettlement::findOrFail($first->json('data.0.id'))->net_total
        );
        $this->assertNull($this->dueRow());
    }

    public function test_a_negative_only_balance_is_not_listed_and_cannot_be_paid(): void
    {
        // سدّد كل المستحقات أولًا، ثم سجّل تعديلًا سالبًا: يصبح الرصيد سالبًا بلا
        // أي مبلغ موجب قابل للتحويل.
        $this->deliveredPaidOrder();
        $this->pay()->assertOk();
        $this->adjust(-20);

        // لا مستحقات موجبة: لا يظهر في قائمة الأدمن.
        $this->assertNull($this->dueRow());

        $this->pay()->assertStatus(422);
        $this->assertSame(1, PlatformSettlement::where('status', PlatformSettlement::STATUS_PAID)->count());
        // التعديل يبقى معلّقًا ولم يُفقد.
        $this->assertSame(1, PlatformSettlementAdjustment::whereNull('settlement_id')->count());
    }

    public function test_adjustments_that_zero_out_the_draft_are_refused_with_a_clear_message(): void
    {
        $this->deliveredPaidOrder();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated();
        $this->adjust(-100);

        $response = $this->pay()->assertStatus(422);
        $this->assertStringContainsString('صفرًا أو سالبًا', $response->json('message'));

        $this->assertSame(0, PlatformSettlement::where('status', PlatformSettlement::STATUS_PAID)->count());
        // المسودة لم تُمسّ ولا التعديل رُبط.
        $this->assertSame(100.0, (float) PlatformSettlement::first()->net_total);
        $this->assertSame(1, PlatformSettlementAdjustment::whereNull('settlement_id')->count());
    }

    public function test_a_zero_adjustment_is_rejected_at_validation(): void
    {
        $this->deliveredPaidOrder();

        $this->postJson('/api/admin/platform-settlements/adjustments', [
            'pharmacy_id' => $this->pharmacyA->id,
            'effective_period_start' => '2026-10-27',
            'amount' => 0,
            'reason' => 'صفر',
        ])->assertStatus(422);
    }
}
