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
        // C4: الـ avatar URL يجب أن يكون رابط Cloudinary فقط.
        $avatarUrl = 'https://res.cloudinary.com/demo/image/upload/v1/patient-avatar.jpg';

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
        // C4: الـ logo URL يجب أن يكون رابط Cloudinary فقط.
        $logoUrl = 'https://res.cloudinary.com/demo/image/upload/v1/pharmacy-logo.jpg';

        Sanctum::actingAs($pharmacyUser, ['*']);
        $this->postJson('/api/profile/pharmacy', ['logo_url' => $logoUrl])
            ->assertOk()
            ->assertJsonPath('data.logo_url', $logoUrl);

        $this->assertSame($logoUrl, Pharmacy::where('user_id', $pharmacyUser->id)->first()->logo);
    }

    public function test_avatar_url_rejects_non_https(): void
    {
        // C4: http:// و javascript: و data: يجب أن تُرفض.
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient, ['*']);

        $this->postJson('/api/profile/patient', ['avatar_url' => 'http://example.com/x.jpg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_url');

        $this->postJson('/api/profile/patient', ['avatar_url' => 'javascript:alert(1)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_url');

        $this->postJson('/api/profile/patient', ['avatar_url' => 'data:text/html,<script>alert(1)</script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_url');
    }

    public function test_avatar_url_rejects_non_cloudinary_https(): void
    {
        // C4: https://example.com يُرفض لأنه ليس من Cloudinary.
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient, ['*']);

        $this->postJson('/api/profile/patient', ['avatar_url' => 'https://example.com/avatar.jpg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar_url');
    }
}