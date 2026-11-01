<?php

namespace Tests\Feature\Api;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SubOrder;
use App\Models\User;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class PlatformSettlementApiTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
    }

    public function test_only_delivered_and_paid_suborders_are_settled_once_without_touching_pos_stock_or_cash(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $a = $this->subOrderFor($order, $this->pharmacyA);
        $b = $this->subOrderFor($order, $this->pharmacyB);
        $stockBeforeSettlement = (int) $this->pmA->fresh()->quantity;
        $a->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $b->update(['status' => SubOrder::STATUS_READY, 'delivered_at' => null]);
        $this->admin->syncRoles(['admin']);

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/admin/platform-settlements/eligible?pharmacy_id='.$this->pharmacyA->id.'&period_start=2026-10-27&period_end=2026-11-02')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sub_order_id', $a->id)
            ->assertJsonPath('data.0.amount', 100)->assertJsonPath('data.0.currency', 'ILS');
        $this->getJson('/api/admin/platform-settlements/eligible?pharmacy_id='.$this->pharmacyB->id.'&period_start=2026-10-27&period_end=2026-11-02')
            ->assertOk()->assertJsonCount(0, 'data');

        $response = $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated()->assertJsonPath('data.orders_total', 100)
            ->assertJsonPath('data.adjustments_total', 0)->assertJsonPath('data.net_total', 100)
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.currency', 'ILS')
            ->assertJsonCount(1, 'data.items');

        $settlementId = $response->json('data.id');
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertStatus(422);

        $this->assertSame($stockBeforeSettlement, (int) $this->pmA->fresh()->quantity);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, CashMovement::count());

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->getJson('/api/pharmacy/accounting/platform-settlements')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $settlementId);
        $this->getJson('/api/pharmacy/accounting/platform-settlements/'.$settlementId)->assertOk()
            ->assertJsonPath('data.items.0.order_id', $order->id);
        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->getJson('/api/pharmacy/accounting/platform-settlements/'.$settlementId)->assertNotFound();
    }

    public function test_unpaid_or_non_delivered_orders_are_not_eligible_and_admin_only_can_manage_settlements(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $a = $this->subOrderFor($order, $this->pharmacyA);
        $a->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->patient, 'sanctum');
        $this->getJson('/api/admin/platform-settlements')->assertForbidden();
        $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertForbidden();
        $this->getJson('/api/pharmacy/accounting/platform-settlements')->assertForbidden();

        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/admin/platform-settlements/eligible?pharmacy_id='.$this->pharmacyA->id.'&period_start=2026-10-27&period_end=2026-11-02')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_adjustments_are_snapshotted_and_paid_settlement_cannot_be_paid_twice(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $sub = $this->subOrderFor($order, $this->pharmacyA);
        $sub->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/admin/platform-settlements/adjustments', [
            'pharmacy_id' => $this->pharmacyA->id,
            'effective_period_start' => '2026-10-27',
            'amount' => -15,
            'reason' => 'تصحيح اختبار موثق',
        ])->assertCreated();

        $response = $this->postJson('/api/admin/platform-settlements', [
            'pharmacy_id' => $this->pharmacyA->id,
            'period_start' => '2026-10-27',
            'period_end' => '2026-11-02',
        ])->assertCreated()->assertJsonPath('data.adjustments_total', -15)->assertJsonPath('data.net_total', 85);
        $id = $response->json('data.id');

        $this->postJson("/api/admin/platform-settlements/{$id}/paid", [
            'transfer_reference' => 'BANK-TEST-001',
            'paid_at' => '2026-11-01 12:00:00',
        ])->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.transfer_reference', 'BANK-TEST-001');
        $this->postJson("/api/admin/platform-settlements/{$id}/paid", [
            'transfer_reference' => 'BANK-TEST-002',
            'paid_at' => '2026-11-01 12:10:00',
        ])->assertStatus(422);
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, Sale::count());
    }
}
