<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientMedicineDetailTest extends TestCase
{
    public function test_patient_medicine_detail_requires_authentication(): void
    {
        $medicine = Medicine::factory()->create();

        $this->getJson("/api/patient/medicines/{$medicine->id}")
            ->assertStatus(401);
    }

    public function test_patient_medicine_detail_returns_image_url(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $medicine->id)
            ->assertJsonPath('data.trade_name', 'Panadol')
            ->assertJsonPath('data.active_ingredient', 'Paracetamol')
            ->assertJsonStructure([
                'data' => ['id', 'trade_name', 'active_ingredient', 'image_url', 'is_available'],
            ]);

        $this->assertArrayHasKey('image_url', $response->json('data'));
        $this->assertArrayNotHasKey('image', $response->json('data'));
    }

    public function test_patient_medicine_detail_returns_nearest_pharmacy_when_geo_provided(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $pharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_available' => true,
            'quantity' => 20,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson(
            "/api/patient/medicines/{$medicine->id}"
            .'?latitude=31.5&longitude=34.47'
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $nearest = $response->json('data.nearest_pharmacy');
        $this->assertIsArray($nearest);
        $this->assertSame($pharmacy->id, $nearest['id']);
        $this->assertArrayHasKey('name', $nearest);
        $this->assertArrayHasKey('distance_km', $nearest);
        $this->assertArrayHasKey('availability_status', $nearest);
    }

    public function test_patient_medicine_detail_404_for_missing(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/medicines/999999');

        $response->assertStatus(404);
    }
}