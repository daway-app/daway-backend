<?php

namespace Tests\Feature\Api;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Sale;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('local');
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

        $paid = $this->withHeaders(['Accept' => 'application/json'])->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
            'receipt' => UploadedFile::fake()->create('bank-notice.png', 120, 'image/png'),
            'transfer_reference' => 'BANK-TEST-001',
        ])->assertOk()->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.transfer_reference', 'BANK-TEST-001')
            ->assertJsonPath('data.0.receipt_available', true);
        $this->assertSame($id, $paid->json('data.0.id'));
        $this->assertSame(0, $this->getJson('/api/admin/platform-settlements/due')->json('data.0.total_due') ?? 0);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->getJson('/api/pharmacy/accounting/platform-settlements')->assertOk()
            ->assertJsonPath('outstanding.total_due', 0)->assertJsonPath('data.0.receipt_available', true);
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$id.'/receipt')->assertOk();
        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$id.'/receipt')->assertNotFound();

        $this->actingAs($this->admin, 'sanctum');
        $this->get('/api/admin/platform-settlements/'.$id.'/receipt')->assertOk();
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
            'receipt' => UploadedFile::fake()->create('bank-notice-again.png', 120, 'image/png'),
        ])->assertStatus(422);
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, Sale::count());
    }

    public function test_admin_due_list_only_includes_pharmacies_with_delivered_and_customer_paid_orders(): void
    {
        Storage::fake('local');
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);
        $subA->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $subB->update(['status' => SubOrder::STATUS_READY, 'delivered_at' => null]);
        $this->admin->syncRoles(['admin']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/platform-settlements/due')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $this->pharmacyA->id)
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.total_due', 100);

        $this->withHeaders(['Accept' => 'application/json'])->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
            'receipt' => UploadedFile::fake()->create('transfer-proof.png', 80, 'image/png'),
        ])->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.orders_total', 100);
        $this->getJson('/api/admin/platform-settlements/due')->assertOk()->assertJsonCount(0, 'data');
    }
}
