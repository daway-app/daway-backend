<?php

namespace Tests\Feature\Api\Patient;

use App\Models\MedicalProfile;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HealthProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_profile_requires_authentication(): void
    {
        $this->getJson('/api/patient/health-profile')->assertStatus(401);
        $this->putJson('/api/patient/health-profile')->assertStatus(401);
    }

    public function test_health_profile_rejects_non_patient(): void
    {
        Sanctum::actingAs(User::factory()->pharmacy()->create());
        $this->getJson('/api/patient/health-profile')->assertStatus(403);
    }

    public function test_health_profile_get_creates_with_defaults(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/health-profile')->assertOk();
        $response->assertJsonPath('data.user_id', $patient->id)
            ->assertJsonPath('data.allergies', [])
            ->assertJsonPath('data.chronic_diseases', [])
            ->assertJsonPath('data.blood_type', null)
            ->assertJsonPath('data.notes', null);

        $this->assertSame(1, MedicalProfile::count());
    }

    public function test_health_profile_put_updates_fields(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/patient/health-profile')->assertOk();

        $payload = [
            'allergies' => ['Penicillin', 'Aspirin'],
            'chronic_diseases' => ['Diabetes'],
            'blood_type' => 'A+',
            'notes' => 'some note',
        ];

        $this->putJson('/api/patient/health-profile', $payload)->assertOk();

        $profile = MedicalProfile::where('user_id', $patient->id)->first();
        $this->assertEquals(['Penicillin', 'Aspirin'], $profile->allergies);
        $this->assertEquals(['Diabetes'], $profile->chronic_diseases);
        $this->assertSame('A+', $profile->blood_type);
        $this->assertSame('some note', $profile->notes);
    }

    public function test_health_profile_isolation_between_patients_idor(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();

        Sanctum::actingAs($a);
        $this->putJson('/api/patient/health-profile', [
            'allergies' => ['Penicillin'],
        ])->assertOk();

        Sanctum::actingAs($b);
        $bResponse = $this->getJson('/api/patient/health-profile')->assertOk();
        $bResponse->assertJsonPath('data.allergies', []);

        $aProfile = MedicalProfile::where('user_id', $a->id)->first();
        $bProfile = MedicalProfile::where('user_id', $b->id)->first();
        $this->assertEquals(['Penicillin'], $aProfile->allergies);
        $this->assertEquals([], $bProfile->allergies);
    }

    public function test_health_profile_put_ignores_last_local_update_field(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/patient/health-profile')->assertOk();

        $this->putJson('/api/patient/health-profile', [
            'allergies' => ['Penicillin'],
            'last_local_update' => '2026-09-02 12:00:00',
        ])->assertOk();

        $this->assertFalse(
            MedicalProfile::first()->getAttributes()['last_local_update'] ?? false,
            'last_local_update must not be written anywhere in the DB.'
        );
    }
}
