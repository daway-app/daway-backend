<?php

namespace Tests\Feature\Api;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Tests\TestCase;

class RefundApiTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);
        return [$user, $pharmacy];
    }

    private function createSaleWithItems(Pharmacy $pharmacy, int $itemCount = 2): Sale
    {
        $sale = Sale::create([
            'pharmacy_id' => $pharmacy->id,
            'number' => 'INV-'.rand(10000, 99999),
            'customer_id' => null,
            'customer_name' => null,
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'paid' => 0,
            'remaining' => 0,
            'payment_method' => Sale::METHOD_CASH,
            'status' => Sale::STATUS_PAID,
            'items_count' => $itemCount,
            'created_by' => $pharmacy->user_id,
            'sold_at' => now(),
        ]);

        $total = 0;
        for ($i = 0; $i < $itemCount; $i++) {
            $quantity = 5;
            $unitPrice = 100;
            $lineTotal = $quantity * $unitPrice;
            $total += $lineTotal;

            SaleItem::create([
                'sale_id' => $sale->id,
                'pharmacy_medicine_id' => null,
                'medicine_id' => null,
                'medicine_name' => 'دواء تجريبي '.$i,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_discount' => 0,
                'line_total' => $lineTotal,
            ]);
        }

        $sale->update([
            'total' => $total,
            'paid' => $total,
            'remaining' => 0,
        ]);

        return $sale;
    }

    public function test_can_list_refunds(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $this->actingAs($user);

        $response = $this->getJson(route('api.pharmacy.accounting.refunds.index'));

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_can_perform_full_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 2);

        foreach ($sale->items as $item) {
            $pharmacyMedicine = PharmacyMedicine::factory()->for($pharmacy)->create([
                'medicine_id' => Medicine::factory(),
                'quantity' => 100,
                'price' => $item->unit_price,
            ]);
            $item->update(['pharmacy_medicine_id' => $pharmacyMedicine->id]);
        }

        $this->actingAs($user);

        $items = $sale->items->map(fn ($item) => [
            'sale_item_id' => $item->id,
            'quantity' => $item->quantity,
        ])->all();

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => $items,
            'reason' => 'إرجاع بالموافقة',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.refund.status', 'completed');

        $this->assertDatabaseHas('refunds', [
            'sale_id' => $sale->id,
            'amount' => $sale->total,
            'status' => 'completed',
        ]);

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_REFUNDED, $sale->status);
        $this->assertEquals(0, $sale->paid);
    }

    public function test_can_perform_partial_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 2);
        $saleItem = $sale->items->first();

        foreach ($sale->items as $item) {
            $pharmacyMedicine = PharmacyMedicine::factory()->for($pharmacy)->create([
                'medicine_id' => Medicine::factory(),
                'quantity' => 100,
                'price' => $item->unit_price,
            ]);
            $item->update(['pharmacy_medicine_id' => $pharmacyMedicine->id]);
        }

        $this->actingAs($user);

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
            'reason' => 'إرجاع جزئي',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_PARTIALLY_PAID, $sale->status);
    }

    public function test_prevents_double_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 1);

        foreach ($sale->items as $item) {
            $pharmacyMedicine = PharmacyMedicine::factory()->for($pharmacy)->create([
                'medicine_id' => Medicine::factory(),
                'quantity' => 100,
                'price' => $item->unit_price,
            ]);
            $item->update(['pharmacy_medicine_id' => $pharmacyMedicine->id]);
        }

        $this->actingAs($user);

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'quantity' => $sale->items->first()->quantity],
            ],
            'reason' => 'إرجاع أول',
        ]);

        $response->assertCreated();

        $response2 = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'quantity' => 1],
            ],
            'reason' => 'إرجاع ثاني',
        ]);

        $response2->assertStatus(422);
    }

    public function test_prevents_refund_for_cancelled_sale(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 1);
        $sale->update(['status' => Sale::STATUS_CANCELLED]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_prevents_over_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 1);

        $item = $sale->items->first();
        $pharmacyMedicine = PharmacyMedicine::factory()->for($pharmacy)->create([
            'medicine_id' => Medicine::factory(),
            'quantity' => 100,
            'price' => $item->unit_price,
        ]);
        $item->update(['pharmacy_medicine_id' => $pharmacyMedicine->id]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $item->id, 'quantity' => 999999],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_prevents_refund_for_wrong_pharmacy(): void
    {
        [$user1, $pharmacy1] = $this->pharmacyUserWithPharmacy();
        [$user2, $pharmacy2] = $this->pharmacyUserWithPharmacy();

        $sale = $this->createSaleWithItems($pharmacy1, 1);
        foreach ($sale->items as $item) {
            $pm = PharmacyMedicine::factory()->for($pharmacy1)->create([
                'medicine_id' => Medicine::factory(),
                'price' => $item->unit_price,
            ]);
            $item->update(['pharmacy_medicine_id' => $pm->id]);
        }

        $this->actingAs($user2);

        $response = $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_restores_inventory_on_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 1);

        $item = $sale->items->first();
        $pharmacyMedicine = PharmacyMedicine::factory()->for($pharmacy)->create([
            'medicine_id' => Medicine::factory(),
            'quantity' => 50,
            'price' => $item->unit_price,
        ]);
        $item->update(['pharmacy_medicine_id' => $pharmacyMedicine->id]);

        $this->actingAs($user);

        $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);

        $pharmacyMedicine->refresh();
        $this->assertEquals(52, $pharmacyMedicine->quantity);
    }

    public function test_creates_cash_movement_on_refund(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $sale = $this->createSaleWithItems($pharmacy, 1);

        $item = $sale->items->first();
        PharmacyMedicine::factory()->for($pharmacy)->create([
            'medicine_id' => Medicine::factory(),
            'quantity' => 100,
            'price' => $item->unit_price,
        ]);

        $this->actingAs($user);

        $this->postJson(route('api.pharmacy.accounting.refunds.store'), [
            'sale_number' => $sale->number,
            'items' => [
                ['sale_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);

        $this->assertDatabaseHas('cash_movements', [
            'pharmacy_id' => $pharmacy->id,
            'direction' => CashMovement::DIRECTION_OUT,
            'source_type' => CashMovement::SOURCE_REFUND,
        ]);
    }

    public function test_is_denied_for_unauthorized_user(): void
    {
        $user = User::factory()->patient()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('api.pharmacy.accounting.refunds.index'));

        $response->assertStatus(403);
    }
}