<?php

namespace Tests\Feature\Api\Patient;

use App\Models\DeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_requires_authentication(): void
    {
        $this->postJson('/api/device-tokens')->assertStatus(401);
        $this->deleteJson('/api/device-tokens/current')->assertStatus(401);
    }

    public function test_device_token_store_upserts_on_user_device_id(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/device-tokens', [
            'token' => 'FCM_TOKEN_A',
            'platform' => 'android',
            'device_id' => 'dev-1',
        ])->assertStatus(201);
        $this->assertSame(1, DeviceToken::count());

        $this->postJson('/api/device-tokens', [
            'token' => 'FCM_TOKEN_B',
            'platform' => 'android',
            'device_id' => 'dev-1',
        ])->assertStatus(200);
        $this->assertSame(1, DeviceToken::count(), 'Upsert must not create a second row.');

        $row = DeviceToken::first();
        $this->assertSame('FCM_TOKEN_B', $row->token);
    }

    public function test_device_token_store_rejects_duplicate_token_value(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();
        Sanctum::actingAs($a);
        $this->postJson('/api/device-tokens', [
            'token' => 'FCM_SHARED',
            'platform' => 'android',
            'device_id' => 'a-dev',
        ])->assertStatus(201);

        Sanctum::actingAs($b);
        $this->postJson('/api/device-tokens', [
            'token' => 'FCM_SHARED',
            'platform' => 'android',
            'device_id' => 'b-dev',
        ])->assertStatus(422);
    }

    public function test_device_token_destroy_isolated_to_user_id(): void
    {
        $a = User::factory()->patient()->create();
        $b = User::factory()->patient()->create();
        Sanctum::actingAs($a);
        $this->postJson('/api/device-tokens', [
            'token' => 'A_TOKEN',
            'platform' => 'android',
            'device_id' => 'a-dev',
        ])->assertStatus(201);

        Sanctum::actingAs($b);
        $this->postJson('/api/device-tokens', [
            'token' => 'B_TOKEN',
            'platform' => 'android',
            'device_id' => 'b-dev',
        ])->assertStatus(201);

        Sanctum::actingAs($a);
        $this->deleteJson('/api/device-tokens/current', [
            'device_id' => 'b-dev',
        ])->assertOk();
        $this->assertNotNull(DeviceToken::where('user_id', $b->id)->first(),
            'User B\'s token must remain intact.');
    }

    public function test_device_token_destroy_requires_device_id(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->deleteJson('/api/device-tokens/current', [])
            ->assertStatus(422);
    }

    public function test_device_token_store_rejects_invalid_platform(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/device-tokens', [
            'token' => 'X',
            'platform' => 'windows',
            'device_id' => 'dev',
        ])->assertStatus(422);
    }

    public function test_device_token_store_accepts_android_and_ios(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/device-tokens', [
            'token' => 'T1',
            'platform' => 'android',
            'device_id' => 'd-1',
        ])->assertStatus(201);

        $this->postJson('/api/device-tokens', [
            'token' => 'T2',
            'platform' => 'ios',
            'device_id' => 'd-2',
        ])->assertStatus(201);

        $this->assertSame(2, DeviceToken::count());
    }
}
