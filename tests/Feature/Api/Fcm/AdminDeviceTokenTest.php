<?php

namespace Tests\Feature\Api\Fcm;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDeviceTokenTest extends TestCase
{
    public function test_admin_can_register_refresh_and_remove_own_device_without_exposing_token(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $payload = ['platform' => 'web', 'device_id' => 'admin-browser', 'token' => 'synthetic-admin-token'];

        $this->postJson('/api/device-tokens', $payload)->assertCreated()
            ->assertJsonPath('data.user_id', $admin->id)->assertJsonMissingPath('data.token');
        $payload['token'] = 'synthetic-refreshed-token';
        $this->postJson('/api/device-tokens', $payload)->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $admin->id, 'token' => $payload['token']]);

        $this->deleteJson('/api/device-tokens/current', ['device_id' => 'admin-browser'])->assertOk();
        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_admin_cannot_claim_or_remove_another_users_device(): void
    {
        $patient = User::factory()->patient()->create();
        $patient->deviceTokens()->create([
            'device_id' => 'patient-phone', 'platform' => 'android', 'token' => 'synthetic-patient-token',
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/device-tokens', [
            'device_id' => 'admin-browser', 'platform' => 'web', 'token' => 'synthetic-patient-token',
        ])->assertUnprocessable();
        $this->deleteJson('/api/device-tokens/current', ['device_id' => 'patient-phone'])->assertOk();
        $this->assertDatabaseHas('device_tokens', ['user_id' => $patient->id, 'device_id' => 'patient-phone']);
    }
}
