<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRoleEnforcementTest extends TestCase
{
    public function test_patient_cannot_access_pharmacy_profile(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/profile/pharmacy')->assertForbidden();
    }

    public function test_pharmacy_cannot_access_patient_profile(): void
    {
        $pharmacy = User::factory()->pharmacy()->create();
        Sanctum::actingAs($pharmacy);

        $this->getJson('/api/profile/patient')->assertForbidden();
    }
}