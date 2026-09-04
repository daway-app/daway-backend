<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PatientInquiryReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_reply_requires_authentication(): void
    {
        $inquiry = PatientInquiry::factory()->create();

        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'status' => 'answered',
            'reply' => 'Yes',
        ])->assertStatus(401);
    }

    public function test_inquiry_reply_persists_status_reply_and_availability(): void
    {
        $patientUser = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patientUser->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'متوفر؟',
        ]);

        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'status' => 'answered',
            'reply' => 'نعم متوفر',
            'availability_status' => 'available',
        ])->assertOk();

        $inquiry->refresh();
        $this->assertSame('answered', $inquiry->status);
        $this->assertSame('نعم متوفر', $inquiry->reply);
        $this->assertSame('available', $inquiry->availability_status);
        $this->assertNotNull($inquiry->replied_at);
    }

    public function test_inquiry_reply_creates_inquiry_answered_notification_for_patient(): void
    {
        $patientUser = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patientUser->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'status' => 'answered',
            'reply' => 'متوفر',
        ])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $patientUser->id)
            ->where('type', 'inquiry_answered')
            ->count());
    }

    public function test_inquiry_reply_replied_at_set_on_answer_or_reply(): void
    {
        $patientUser = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        $byAnswer = PatientInquiry::factory()->create([
            'user_id' => $patientUser->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$byAnswer->id}", ['status' => 'answered'])->assertOk();
        $this->assertNotNull($byAnswer->fresh()->replied_at);

        $byReply = PatientInquiry::factory()->create([
            'user_id' => $patientUser->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
        $this->putJson("/api/pharmacy/inquiries/{$byReply->id}", ['reply' => 'yes'])->assertOk();
        $this->assertNotNull($byReply->fresh()->replied_at);
    }

    public function test_inquiry_reply_invalidates_unknown_availability_value(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $inquiry = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id]);

        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'availability_status' => 'unknown',
        ])->assertStatus(422);
    }

    public function test_inquiry_reply_invalidates_availability_out(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $inquiry = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id]);

        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'availability_status' => 'out',
        ])->assertStatus(422);
    }

    public function test_patient_inquiry_index_returns_reply_availability_replied_at(): void
    {
        $patientUser = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patientUser->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        Sanctum::actingAs($pharmacyUser);
        $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'reply' => 'متوفر',
            'availability_status' => 'available',
        ])->assertOk();

        Sanctum::actingAs($patientUser);
        $row = $this->getJson('/api/patient/inquiries')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('متوفر', $row['reply']);
        $this->assertSame('available', $row['availability_status']);
        $this->assertNotEmpty($row['replied_at']);
    }
}
