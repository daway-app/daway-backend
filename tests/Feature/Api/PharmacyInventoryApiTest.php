<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyInventoryApiTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_list_inventory_with_stats(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicineA = Medicine::factory()->create();
        $medicineB = Medicine::factory()->create();
        $medicineC = Medicine::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 20,
            'min_stock' => 5,
            'is_available' => true,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineB->id,
            'price' => 5,
            'quantity' => 5,
            'min_stock' => 5,
            'is_available' => true,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineC->id,
            'price' => 5,
            'quantity' => 0,
            'min_stock' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/inventory');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.available_count', 2)
            ->assertJsonPath('stats.low_count', 1)
            ->assertJsonPath('stats.out_count', 1);
    }

    public function test_pharmacy_can_update_single_inventory_item(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'min_stock' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/pharmacy/inventory/{$pharmacyMedicine->id}", [
            'quantity' => 50,
            'min_stock' => 10,
            'is_available' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 50)
            ->assertJsonPath('data.min_stock', 10);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
            'quantity' => 50,
            'min_stock' => 10,
        ]);
    }

    public function test_pharmacy_can_bulk_update_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicineA = Medicine::factory()->create();
        $medicineB = Medicine::factory()->create();
        $medicineC = Medicine::factory()->create();

        $pmA = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);
        $pmB = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineB->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);
        $pmC = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineC->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/inventory/bulk', [
            'items' => [
                ['id' => $pmA->id, 'quantity' => 25, 'min_stock' => 5],
                ['id' => $pmB->id, 'quantity' => 30, 'min_stock' => 5],
                ['id' => $pmC->id, 'quantity' => 40, 'min_stock' => 5],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 3);

        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pmA->id, 'quantity' => 25]);
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pmB->id, 'quantity' => 30]);
        $this->assertDatabaseHas('pharmacy_medicines', ['id' => $pmC->id, 'quantity' => 40]);
    }

    public function test_bulk_update_skips_foreign_items(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB, $pharmacyB] = $this->pharmacyUserWithPharmacy();

        $medicineA = Medicine::factory()->create();
        $medicineB = Medicine::factory()->create();

        $pmA = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);
        $pmB = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyB->id,
            'medicine_id' => $medicineB->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->postJson('/api/pharmacy/inventory/bulk', [
            'items' => [
                ['id' => $pmA->id, 'quantity' => 50, 'min_stock' => 5],
                ['id' => $pmB->id, 'quantity' => 99, 'min_stock' => 5],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pmA->id,
            'quantity' => 50,
        ]);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pmB->id,
            'quantity' => 10,
        ]);
    }

    public function test_low_stock_notification_on_update(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol']);

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'min_stock' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/inventory/{$pharmacyMedicine->id}", [
            'quantity' => 3,
            'min_stock' => 5,
            'is_available' => true,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'low_stock',
        ]);
    }

    public function test_out_of_stock_notification_on_update(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol']);

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'min_stock' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/inventory/{$pharmacyMedicine->id}", [
            'quantity' => 0,
            'min_stock' => 5,
            'is_available' => true,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'out_of_stock',
        ]);
    }
}
