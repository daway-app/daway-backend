<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientMedicineSearchTest extends TestCase
{
    public function test_patient_medicine_search_requires_authentication(): void
    {
        $this->getJson('/api/patient/medicines/search?q=pan')
            ->assertStatus(401);
    }

    public function test_patient_medicine_search_returns_image_url_not_image(): void
    {
        $patient = User::factory()->patient()->create();

        Medicine::factory()->create([
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
            'image' => 'medicines/panadol.jpg',
        ]);

        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/medicines/search?q=pan');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'medicines' => [
                        ['id', 'trade_name', 'image_url', 'is_available'],
                    ],
                    'moh_catalog',
                ],
            ]);

        $medicines = $response->json('data.medicines');
        $this->assertNotEmpty($medicines);
        $this->assertArrayHasKey('image_url', $medicines[0]);
        $this->assertIsString($medicines[0]['image_url']);
        $this->assertArrayNotHasKey('image', $medicines[0]);

        $moh = $response->json('data.moh_catalog');
        $this->assertNotEmpty($moh);
        $this->assertArrayNotHasKey('image', $moh[0]);
    }

    public function test_patient_medicine_search_filters_by_radius_km(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'PanadolFilter',
            'active_ingredient' => 'Paracetamol',
        ]);

        $near1 = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
            'pharmacy_name' => 'Near One',
        ]);
        $near2 = Pharmacy::factory()->create([
            'latitude' => 31.5020,
            'longitude' => 34.4650,
            'pharmacy_name' => 'Near Two',
        ]);
        $far = Pharmacy::factory()->create([
            'latitude' => 31.0000,
            'longitude' => 34.0000,
            'pharmacy_name' => 'Far Away',
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $near1->id,
            'is_available' => true,
            'quantity' => 20,
        ]);
        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $near2->id,
            'is_available' => true,
            'quantity' => 15,
        ]);
        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $far->id,
            'is_available' => true,
            'quantity' => 20,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson(
            '/api/patient/medicines/search?q=PanadolFilter'
            .'&latitude=31.5&longitude=34.47&radius_km=10'
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $medicines = $response->json('data.medicines');
        $this->assertCount(1, $medicines);

        $first = $medicines[0];
        $this->assertSame($medicine->id, $first['id']);
        $this->assertSame(2, $first['available_pharmacies_count']);
        $this->assertNotNull($first['nearest_pharmacy']);
        $this->assertLessThanOrEqual(10.0, $first['nearest_pharmacy']['distance_km']);
    }

    public function test_patient_medicine_search_includes_available_pharmacies_count_and_nearest(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'PanadolGeo',
            'active_ingredient' => 'Paracetamol',
        ]);

        $pharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_available' => true,
            'quantity' => 25,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson(
            '/api/patient/medicines/search?q=PanadolGeo'
            .'&latitude=31.5&longitude=34.47'
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $first = $response->json('data.medicines.0');
        $this->assertIsInt($first['available_pharmacies_count']);
        $this->assertSame(1, $first['available_pharmacies_count']);
        $this->assertIsArray($first['nearest_pharmacy']);
        $this->assertSame($pharmacy->id, $first['nearest_pharmacy']['id']);
        $this->assertArrayHasKey('distance_km', $first['nearest_pharmacy']);
        $this->assertArrayHasKey('availability_status', $first['nearest_pharmacy']);
    }

    public function test_patient_medicine_search_respects_per_page_for_moh_catalog(): void
    {
        $patient = User::factory()->patient()->create();

        Medicine::factory()->create(['trade_name' => 'Panadol One', 'active_ingredient' => 'Paracetamol']);

        MohMedicine::create(['trade_name' => 'Panadol Alpha', 'generic_name' => 'Paracetamol']);
        MohMedicine::create(['trade_name' => 'Panadol Beta',  'generic_name' => 'Paracetamol']);
        MohMedicine::create(['trade_name' => 'Panadol Gamma', 'generic_name' => 'Paracetamol']);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/medicines/search?q=pan&page=1&per_page=2');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['medicines', 'moh_catalog'],
            ]);

        $this->assertLessThanOrEqual(2, count($response->json('data.medicines')));
    }
}