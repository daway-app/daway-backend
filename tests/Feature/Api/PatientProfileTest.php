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
                'data' => ['type', 'name', 'phone', 'avatar_url', 'birth_date', 'latitude', 'longitude', 'address'],
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
}
