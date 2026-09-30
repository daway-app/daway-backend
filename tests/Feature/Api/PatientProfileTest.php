<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientProfileTest extends TestCase
{
    public function test_patient_can_view_own_profile(): void
    {
        $patient = User::factory()->patient()->create(['name' => 'Test Patient']);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/profile/patient');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['type', 'name', 'phone', 'avatar_url', 'age', 'birth_date', 'notifications_enabled', 'terms_accepted', 'latitude', 'longitude', 'address'],
            ])
            ->assertJsonPath('data.type', 'patient')
            ->assertJsonPath('data.name', 'Test Patient');
    }

    public function test_pharmacy_user_cannot_view_patient_profile(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();

        Sanctum::actingAs($pharmacyUser);

        $this->getJson('/api/profile/patient')->assertStatus(403);
    }

    public function test_terms_accepted_cannot_be_set_via_profile_api(): void
    {
        $patient = User::factory()->patient()->create([
            'name' => 'Test Patient',
            'terms_accepted' => false,
        ]);

        Sanctum::actingAs($patient);

        $this->postJson('/api/profile/patient', [
            'terms_accepted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.terms_accepted', false);

        $this->assertDatabaseHas('users', [
            'id' => $patient->id,
            'terms_accepted' => false,
        ]);
    }

    public function test_name_and_notifications_can_still_be_updated_via_profile_api(): void
    {
        $patient = User::factory()->patient()->create([
            'name' => 'Original Name',
            'notifications_enabled' => false,
        ]);

        Sanctum::actingAs($patient);

        $this->postJson('/api/profile/patient', [
            'name' => 'Updated Name',
            'notifications_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.notifications_enabled', true);

        $this->assertDatabaseHas('users', [
            'id' => $patient->id,
            'name' => 'Updated Name',
            'notifications_enabled' => true,
        ]);
    }
}
