<?php

namespace Tests\Feature\Api\Patient;

use App\Models\AvailabilityNotification;
use App\Models\DeviceToken;
use App\Models\Favorite;
use App\Models\MedicalProfile;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthIorTest extends TestCase
{
    public function test_patient_cannot_see_other_patient_favorites(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Favorite::create([
            'user_id' => $patientA->id,
            'favoritable_type' => Medicine::class,
            'favoritable_id' => $medicine->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $listResponse = $this->getJson('/api/patient/favorites/medicines');

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);

        $deleteResponse = $this->deleteJson("/api/patient/favorites/medicines/{$medicine->id}");

        $deleteResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Favorite::where('user_id', $patientA->id)->count());
    }

    public function test_patient_cannot_see_or_update_other_patient_health_profile(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();

        MedicalProfile::firstOrCreate(
            ['user_id' => $patientA->id],
            [
                'allergies' => ['penicillin'],
                'chronic_diseases' => ['diabetes'],
                'blood_type' => 'O+',
                'notes' => 'A original notes',
            ]
        );

        MedicalProfile::firstOrCreate(
            ['user_id' => $patientB->id],
            [
                'allergies' => ['pollen'],
                'chronic_diseases' => [],
                'blood_type' => 'A+',
                'notes' => 'B original notes',
            ]
        );

        Sanctum::actingAs($patientB);

        $showResponse = $this->getJson('/api/patient/health-profile');

        $showResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $patientB->id)
            ->assertJsonPath('data.blood_type', 'A+')
            ->assertJsonPath('data.notes', 'B original notes');

        $updateResponse = $this->putJson('/api/patient/health-profile', [
            'allergies' => ['sulfa'],
            'chronic_diseases' => ['asthma'],
            'blood_type' => 'B-',
            'notes' => 'B updated notes',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $patientB->id)
            ->assertJsonPath('data.blood_type', 'B-')
            ->assertJsonPath('data.notes', 'B updated notes');

        $aProfile = MedicalProfile::where('user_id', $patientA->id)->first();

        $this->assertNotNull($aProfile);
        $this->assertSame(['penicillin'], $aProfile->allergies);
        $this->assertSame(['diabetes'], $aProfile->chronic_diseases);
        $this->assertSame('O+', $aProfile->blood_type);
        $this->assertSame('A original notes', $aProfile->notes);
    }

    public function test_patient_cannot_delete_other_patient_availability_alert(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $alert = AvailabilityNotification::create([
            'user_id' => $patientA->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => null,
            'is_notified' => false,
            'notified_at' => null,
        ]);

        Sanctum::actingAs($patientB);

        $response = $this->deleteJson("/api/patient/availability-alerts/{$alert->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('availability_notifications', [
            'id' => $alert->id,
            'user_id' => $patientA->id,
        ]);
    }

    public function test_patient_cannot_delete_other_patient_device_token(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();

        DeviceToken::create([
            'user_id' => $patientA->id,
            'token' => 'fcm-token-a',
            'platform' => 'android',
            'device_id' => 'a-dev',
            'last_seen_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $response = $this->deleteJson('/api/device-tokens/current', [
            'device_id' => 'a-dev',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $patientA->id,
            'device_id' => 'a-dev',
        ]);
    }

    public function test_patient_cannot_see_other_patient_inquiries(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $medicine = Medicine::factory()->create();

        $aInquiry = PatientInquiry::factory()->create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'A inquiry message',
        ]);

        Sanctum::actingAs($patientB);

        $response = $this->getJson('/api/patient/inquiries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);

        $this->assertDatabaseHas('patient_inquiries', [
            'id' => $aInquiry->id,
            'user_id' => $patientA->id,
            'message' => 'A inquiry message',
        ]);
    }

    public function test_patient_cannot_see_other_patient_notifications(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();

        $aNotification = Notification::create([
            'user_id' => $patientA->id,
            'type' => 'low_stock',
            'message' => 'A private notification',
            'is_read' => false,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $bResponse = $this->getJson('/api/notifications');

        $bResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('unread_count', 0);

        Sanctum::actingAs($patientA);

        $aResponse = $this->getJson('/api/notifications');

        $aResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $aNotification->id);

        Sanctum::actingAs($patientB);

        $markResponse = $this->postJson("/api/notifications/{$aNotification->id}/read");

        $markResponse->assertForbidden();

        $this->assertDatabaseHas('notifications', [
            'id' => $aNotification->id,
            'user_id' => $patientA->id,
            'is_read' => false,
        ]);
    }
}