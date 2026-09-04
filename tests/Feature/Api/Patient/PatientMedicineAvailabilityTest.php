<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientMedicineAvailabilityTest extends TestCase
{
    public function test_patient_availability_requires_authentication(): void
    {
        $medicine = Medicine::factory()->create();

        $this->getJson("/api/patient/medicines/{$medicine->id}/availability")
            ->assertStatus(401);
    }

    public function test_patient_availability_returns_required_fields(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $pharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
            'phone_number' => '0591234567',
            'avg_rating' => 4.50,
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'price' => 12.50,
            'quantity' => 20,
            'is_available' => true,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}/availability");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'pharmacy_id', 'name', 'price', 'quantity',
                    'availability_status', 'distance_km', 'phone',
                    'latitude', 'longitude', 'working_hours', 'rating',
                ]],
            ]);

        $row = $response->json('data.0');
        $this->assertSame($pharmacy->id, $row['pharmacy_id']);
        $this->assertSame(20, $row['quantity']);
        $this->assertContains($row['availability_status'], ['available', 'low_stock', 'out_of_stock']);
        $this->assertNotSame('out', $row['availability_status']);
        $this->assertNotSame('unknown', $row['availability_status']);
    }

    public function test_patient_availability_status_available(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $pharmacy = Pharmacy::factory()->create();

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 50,
            'is_available' => true,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}/availability");

        $response->assertOk()
            ->assertJsonPath('data.0.availability_status', 'available');
    }

    public function test_patient_availability_status_low_stock(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $pharmacy = Pharmacy::factory()->create();

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 5,
            'is_available' => true,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}/availability");

        $response->assertOk()
            ->assertJsonPath('data.0.availability_status', 'low_stock');
    }

public function test_patient_availability_status_out_of_stock(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $pharmacy = Pharmacy::factory()->inactive()->create();

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 20,
            'is_available' => true,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}/availability");

        $response->assertOk()
            ->assertJsonPath('data.0.availability_status', 'out_of_stock');
    }

    public function test_patient_availability_filters_by_radius(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $near = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
            'pharmacy_name' => 'Near',
        ]);
        $far = Pharmacy::factory()->create([
            'latitude' => 31.0000,
            'longitude' => 34.0000,
            'pharmacy_name' => 'Far',
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $near->id,
            'quantity' => 20,
            'is_available' => true,
        ]);
        PharmacyMedicine::factory()->create([
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $far->id,
            'quantity' => 20,
            'is_available' => true,
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson(
            "/api/patient/medicines/{$medicine->id}/availability"
            .'?latitude=31.5&longitude=34.47&radius_km=10'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $near->id);
    }
}