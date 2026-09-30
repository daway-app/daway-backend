<?php

namespace Tests\Feature\Api;

use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientInquiryMessageApiTest extends TestCase
{
    use RefreshDatabase;

    private function patientAndPharmacy(): array
    {
        $patient = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        return [$patient, $pharmacyUser, $pharmacy, $inquiry];
    }

    public function test_patient_can_list_messages_for_own_inquiry(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'Hello pharmacy',
        ]);
        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $pharmacyUser->id,
            'message' => 'Yes, available',
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/patient/inquiries/{$inquiry->id}/messages");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'inquiry_id', 'sender_user_id', 'message', 'is_read', 'created_at']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    public function test_patient_can_send_message_to_own_inquiry(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        Sanctum::actingAs($patient);

        $response = $this->postJson("/api/patient/inquiries/{$inquiry->id}/messages", [
            'message' => 'Is it in stock?',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Is it in stock?');

        $this->assertDatabaseHas('patient_inquiry_messages', [
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'Is it in stock?',
        ]);
    }

    public function test_patient_can_send_message_without_medicine_id_notification(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        Sanctum::actingAs($patient);

        $this->postJson("/api/patient/inquiries/{$inquiry->id}/messages", [
            'message' => 'Test message',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'chat_message',
        ]);
    }

    public function test_patient_cannot_access_other_patient_inquiry_messages(): void
    {
        [$patientA, $pharmacyUser, $pharmacy, $inquiryA] = $this->patientAndPharmacy();
        $patientB = User::factory()->patient()->create();

        Sanctum::actingAs($patientB);

        $this->getJson("/api/patient/inquiries/{$inquiryA->id}/messages")->assertForbidden();
    }

    public function test_patient_cannot_send_message_to_other_patient_inquiry(): void
    {
        [$patientA, $pharmacyUser, $pharmacy, $inquiryA] = $this->patientAndPharmacy();
        $patientB = User::factory()->patient()->create();

        Sanctum::actingAs($patientB);

        $this->postJson("/api/patient/inquiries/{$inquiryA->id}/messages", [
            'message' => 'Hello',
        ])->assertForbidden();
    }

    public function test_patient_message_validation_requires_message(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        Sanctum::actingAs($patient);

        $this->postJson("/api/patient/inquiries/{$inquiry->id}/messages", [
            // no message field
        ])->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_patient_can_mark_messages_as_read(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $pharmacyUser->id,
            'message' => 'Pharmacy reply',
        ]);

        Sanctum::actingAs($patient);

        $this->getJson("/api/patient/inquiries/{$inquiry->id}/messages?mark_read=1")->assertOk();

        $this->assertDatabaseHas('patient_inquiry_messages', [
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $pharmacyUser->id,
        ]);

        $msg = PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)->first();
        $this->assertNotNull($msg->read_at);
    }
}
