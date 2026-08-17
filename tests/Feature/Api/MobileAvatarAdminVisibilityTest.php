<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAvatarAdminVisibilityTest extends TestCase
{
    public function test_patient_avatar_url_shows_in_admin_users_and_patients_pages(): void
    {
        $patient = User::factory()->patient()->create();
        $avatarUrl = 'https://example.com/photos/patient-avatar.jpg';

        Sanctum::actingAs($patient, ['*']);
        $this->postJson('/api/profile/patient', ['avatar_url' => $avatarUrl])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', $avatarUrl);

        $this->assertSame($avatarUrl, $patient->fresh()->avatar);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee($avatarUrl);

        $this->actingAs($admin)->get(route('patients.index'))
            ->assertOk()
            ->assertSee($avatarUrl);
    }

    public function test_pharmacy_logo_url_is_persisted_via_api(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);
        $logoUrl = 'https://example.com/logos/pharmacy-logo.jpg';

        Sanctum::actingAs($pharmacyUser, ['*']);
        $this->postJson('/api/profile/pharmacy', ['logo_url' => $logoUrl])
            ->assertOk()
            ->assertJsonPath('data.logo_url', $logoUrl);

        $this->assertSame($logoUrl, Pharmacy::where('user_id', $pharmacyUser->id)->first()->logo);
    }
}