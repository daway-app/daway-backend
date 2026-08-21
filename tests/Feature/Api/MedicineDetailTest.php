<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Tests\TestCase;

class MedicineDetailTest extends TestCase
{
    public function test_medicine_show_returns_details_with_available_pharmacies(): void
    {
        $medicine = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        $availablePharmacy = Pharmacy::factory()->create();
        $unavailablePharmacy = Pharmacy::factory()->create();

        $available = PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $availablePharmacy->id,
            'is_available' => true,
            'quantity' => 10,
            'price' => 12.50,
        ]);
        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $unavailablePharmacy->id,
            'is_available' => false,
            'quantity' => 0,
            'price' => 9.00,
        ]);

        $response = $this->getJson("/api/medicines/{$medicine->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $medicine->id)
            ->assertJsonPath('data.trade_name', 'Panadol')
            ->assertJsonPath('data.active_ingredient', 'Paracetamol')
            ->assertJsonCount(1, 'data.pharmacies')
            ->assertJsonPath('data.pharmacies.0.pharmacy_id', $availablePharmacy->id)
            ->assertJsonPath('data.pharmacies.0.price', (float) $available->price);
    }

    public function test_medicine_pharmacies_endpoint_returns_only_available(): void
    {
        $medicine = Medicine::factory()->create();

        $availablePharmacy = Pharmacy::factory()->create();
        $unavailablePharmacy = Pharmacy::factory()->create();

        $available = PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $availablePharmacy->id,
            'is_available' => true,
            'quantity' => 5,
            'price' => 15.50,
        ]);
        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $unavailablePharmacy->id,
            'is_available' => true,
            'quantity' => 0,
            'price' => 7.00,
        ]);

        $response = $this->getJson("/api/medicines/{$medicine->id}/pharmacies");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $availablePharmacy->id)
            ->assertJsonPath('data.0.pharmacy_name', $availablePharmacy->pharmacy_name)
            ->assertJsonPath('data.0.price', (float) $available->price)
            ->assertJsonPath('data.0.quantity', 5);
    }

    public function test_by_active_ingredient_returns_matches(): void
    {
        $paracetamol = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        Medicine::factory()->create(['trade_name' => 'Brufen', 'active_ingredient' => 'Ibuprofen']);

        $response = $this->getJson('/api/medicines/active-ingredient/Paracetamol');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paracetamol->id)
            ->assertJsonPath('data.0.trade_name', 'Panadol')
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol');
    }

    public function test_medicine_show_404_for_missing(): void
    {
        $this->getJson('/api/medicines/999999')->assertNotFound();
    }
}