<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientInquiryApiTest extends TestCase
{
    public function test_patient_can_create_inquiry_and_pharmacy_gets_notification(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patient);

        $response = $this->postJson('/api/patient/inquiries', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'هل الدواء متوفر؟',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('patient_inquiries', [
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'هل الدواء متوفر؟',
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'new_inquiry',
        ]);
    }

    public function test_patient_can_list_own_inquiries(): void
    {
        $patient = User::factory()->patient()->create();

        PatientInquiry::factory()->create(['user_id' => $patient->id, 'message' => 'First']);
        PatientInquiry::factory()->create(['user_id' => $patient->id, 'message' => 'Second']);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/inquiries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'status', 'message', 'created_at']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_creation_validates_pharmacy_required(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/patient/inquiries', [
            'medicine_id' => $medicine->id,
            'message' => 'Missing pharmacy',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('pharmacy_id');
    }

    public function test_creation_validates_medicine_required(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/patient/inquiries', [
            'pharmacy_id' => $pharmacy->id,
            'message' => 'Missing medicine',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('medicine_id');
    }
}
