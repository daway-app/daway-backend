<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\ApiPasswordResetNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class EmailPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_password_reset_url' => 'https://frontend.example.test/reset-password']);
        Notification::fake();
    }

    public function test_reset_request_response_does_not_enumerate_accounts_or_allow_pharmacies(): void
    {
        $patient = User::factory()->patient()->create(['email' => 'patient-reset@example.test']);
        $pharmacy = User::factory()->pharmacy()->create(['email' => 'pharmacy-reset@example.test']);
        $valid = $this->postJson('/api/auth/password/email', ['email' => $patient->email])->assertOk();
        $unknown = $this->postJson('/api/auth/password/email', ['email' => 'missing-reset@example.test'])->assertOk();
        $blocked = $this->postJson('/api/auth/password/email', ['email' => $pharmacy->email])->assertOk();
        $this->assertSame($valid->json(), $unknown->json());
        $this->assertSame($valid->json(), $blocked->json());
        Notification::assertSentTo($patient, ApiPasswordResetNotification::class);
        Notification::assertNotSentTo($pharmacy, ApiPasswordResetNotification::class);
    }

    public function test_valid_reset_changes_password_revokes_tokens_and_cannot_be_reused(): void
    {
        $user = User::factory()->patient()->create(['email' => 'reset@example.test']);
        $user->createToken('existing');
        $token = Password::broker()->createToken($user);
        $payload = ['email' => $user->email, 'token' => $token,
            'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'];
        $this->postJson('/api/auth/password/reset', $payload)->assertOk();
        $this->assertTrue(Hash::check('NewStrongPassword123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/password/reset', $payload)->assertUnprocessable();
    }

    public function test_expired_token_and_closed_account_are_rejected(): void
    {
        $expired = User::factory()->patient()->create(['email' => 'expired@example.test']);
        $token = Password::broker()->createToken($expired);
        $this->travel(61)->minutes();
        $payload = ['email' => $expired->email, 'token' => $token,
            'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'];
        $this->postJson('/api/auth/password/reset', $payload)->assertUnprocessable();
        $this->travelBack();

        $closed = User::factory()->patient()->create(['email' => 'closed-reset@example.test']);
        $closedToken = Password::broker()->createToken($closed);
        $closed->forceFill(['closed_at' => now()])->save();
        $payload['email'] = $closed->email;
        $payload['token'] = $closedToken;
        $this->postJson('/api/auth/password/reset', $payload)->assertUnprocessable();
    }
}
