<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    public function test_logout_revokes_token(): void
    {
        $patient = User::factory()->patient()->create();
        $token = $patient->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // Sanctum's RequestGuard caches the user per container; force re-resolution
        // so the (now-deleted) token is actually re-checked on the next request.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/reminders')->assertStatus(401);
    }

    public function test_refresh_token_issues_new_token_and_revokes_old(): void
    {
        $patient = User::factory()->patient()->create();
        $oldToken = $patient->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($oldToken)->postJson('/api/refresh-token');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $newToken = $response->json('token');
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($oldToken, $newToken);

        $this->app['auth']->forgetGuards();

        $this->withToken($oldToken)->getJson('/api/reminders')->assertStatus(401);
    }
}