<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Tests\TestCase;

class PharmacyApiTest extends TestCase
{
    public function test_index_excludes_inactive_pharmacies(): void
    {
        Pharmacy::factory()->create(['is_active' => true, 'pharmacy_name' => 'Active Pharmacy']);
        Pharmacy::factory()->create(['is_active' => false, 'pharmacy_name' => 'Inactive Pharmacy']);

        $response = $this->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_name', 'Active Pharmacy')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'pharmacy_name', 'address', 'latitude', 'longitude',
                    'phone_number', 'logo', 'avg_rating', 'ratings_count', 'ratings_avg',
                ]],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    public function test_index_filters_pharmacies_by_medicine_id(): void
    {
        $medicine = Medicine::factory()->create();

        $withStock = Pharmacy::factory()->create(['pharmacy_name' => 'Has Stock']);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $withStock->id,
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'is_available' => true,
        ]);

        $outOfStock = Pharmacy::factory()->create(['pharmacy_name' => 'Out Of Stock']);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $outOfStock->id,
            'medicine_id' => $medicine->id,
            'quantity' => 0,
            'is_available' => true,
        ]);

        Pharmacy::factory()->create(['pharmacy_name' => 'Unrelated']);

        $response = $this->getJson('/api/pharmacies?medicine_id='.$medicine->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_name', 'Has Stock');
    }
}
