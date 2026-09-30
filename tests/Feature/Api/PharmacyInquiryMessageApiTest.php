<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyInquiryMessageApiTest extends TestCase
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

    public function test_pharmacy_can_list_messages_for_own_inquiry(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'Is medicine available?',
        ]);
        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $pharmacyUser->id,
            'message' => 'Yes, it is',
        ]);

        Sanctum::actingAs($pharmacyUser);

        $response = $this->getJson("/api/pharmacy/inquiries/{$inquiry->id}/messages");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'inquiry_id', 'sender_user_id', 'message', 'is_read', 'created_at']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);
    }

    public function test_pharmacy_can_send_message_to_inquiry(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        Sanctum::actingAs($pharmacyUser);

        $response = $this->postJson("/api/pharmacy/inquiries/{$inquiry->id}/messages", [
            'message' => 'Yes, available now',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', 'Yes, available now');

        $this->assertDatabaseHas('patient_inquiry_messages', [
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $pharmacyUser->id,
            'message' => 'Yes, available now',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $patient->id,
            'type' => 'chat_message',
        ]);
    }

    public function test_pharmacy_cannot_access_another_pharmacy_inquiry_messages(): void
    {
        [$patient, $pharmacyUserA, $pharmacyA, $inquiryA] = $this->patientAndPharmacy();
        $pharmacyUserB = User::factory()->pharmacy()->create();

        Sanctum::actingAs($pharmacyUserB);

        $this->getJson("/api/pharmacy/inquiries/{$inquiryA->id}/messages")->assertForbidden();
    }

    public function test_pharmacy_cannot_send_message_to_another_pharmacy_inquiry(): void
    {
        [$patient, $pharmacyUserA, $pharmacyA, $inquiryA] = $this->patientAndPharmacy();
        $pharmacyUserB = User::factory()->pharmacy()->create();

        Sanctum::actingAs($pharmacyUserB);

        $this->postJson("/api/pharmacy/inquiries/{$inquiryA->id}/messages", [
            'message' => 'Hello',
        ])->assertForbidden();
    }

    public function test_pharmacy_message_validation_requires_message(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        Sanctum::actingAs($pharmacyUser);

        $this->postJson("/api/pharmacy/inquiries/{$inquiry->id}/messages", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_pharmacy_can_mark_patient_messages_as_read(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'Patient question',
        ]);

        Sanctum::actingAs($pharmacyUser);

        $this->getJson("/api/pharmacy/inquiries/{$inquiry->id}/messages?mark_read=1")->assertOk();

        $msg = PatientInquiryMessage::where('patient_inquiry_id', $inquiry->id)->first();
        $this->assertNotNull($msg->read_at);
    }

    public function test_patient_cannot_send_message_without_auth(): void
    {
        [$patient, $pharmacyUser, $pharmacy, $inquiry] = $this->patientAndPharmacy();

        $this->postJson("/api/patient/inquiries/{$inquiry->id}/messages", [
            'message' => 'Hello',
        ])->assertStatus(401);
    }
}
