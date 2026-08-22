<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyMedicineApiTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_list_own_medicines(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicineA = Medicine::factory()->create();
        $medicineB = Medicine::factory()->create();

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
            'price' => 7,
            'quantity' => 3,
            'min_stock' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'medicine_id', 'pharmacy_id', 'price', 'quantity', 'min_stock',
                    'is_available', 'is_low_stock', 'is_out_of_stock', 'medicine' => ['id', 'trade_name'],
                ]],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_patient_cannot_list_pharmacy_medicines(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/medicines')->assertForbidden();
    }

    public function test_pharmacy_can_create_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'quantity' => 10,
            'min_stock' => 5,
            'price' => 12.50,
            'is_available' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.min_stock', 5)
            ->assertJsonPath('data.price', (float) 12.50)
            ->assertJsonPath('data.medicine.id', $medicine->id);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 10,
            'min_stock' => 5,
            'price' => 12.50,
        ]);
    }

    public function test_duplicate_medicine_returns_422(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'price' => 6,
            'is_available' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('medicine_id');
    }

    public function test_pharmacy_can_update_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'min_stock' => 3,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'min_stock' => 8,
            'price' => 6,
            'is_available' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 20)
            ->assertJsonPath('data.min_stock', 8);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
            'quantity' => 20,
            'min_stock' => 8,
        ]);
    }

    public function test_pharmacy_can_delete_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
        ]);
    }

    public function test_pharmacy_cannot_modify_another_pharmacys_medicine(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicineA = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 7,
            'is_available' => true,
        ]);

        Sanctum::actingAs($userB);

        $this->putJson("/api/pharmacy/medicines/{$pharmacyMedicineA->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 99,
            'price' => 1,
            'is_available' => true,
        ])->assertNotFound();

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicineA->id,
            'quantity' => 7,
            'price' => 5,
        ]);
    }

    public function test_pharmacy_search_returns_catalog(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines/search?q=Pan');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['medicines', 'moh_catalog'],
            ])
            ->assertJsonPath('data.medicines.0.name', 'Panadol')
            ->assertJsonPath('data.moh_catalog.0.name', 'Panadol Extra');
    }

    public function test_alternatives_by_active_ingredient(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicineA = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        $medicineB = Medicine::factory()->create(['trade_name' => 'Paracetol', 'active_ingredient' => 'Paracetamol']);
        $medicineC = Medicine::factory()->create(['trade_name' => 'Brufen', 'active_ingredient' => 'Ibuprofen']);

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}/alternatives");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $medicineB->id)
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($medicineC->id, $ids);
    }
}
