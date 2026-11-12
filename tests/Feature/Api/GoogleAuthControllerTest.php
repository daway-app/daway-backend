<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $verifier = Mockery::mock(GoogleIdentityVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(['sub' => 'subject-123', 'email' => 'patient@example.test', 'email_verified' => true]);
        $this->app->instance(GoogleIdentityVerifier::class, $verifier);
    }

    private function nonce(string $purpose): string
    {
        return $this->postJson('/api/auth/google/challenge', ['purpose' => $purpose])->assertOk()->json('nonce');
    }

    public function test_pharmacy_registration_requires_google_and_cannot_login_before_approval(): void
    {
        $payload = ['pharmacy_name' => 'New Pharmacy', 'phone' => '0591234501', 'region' => 'Gaza',
            'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'];
        $this->postJson('/api/auth/google/register-pharmacy', $payload)->assertUnprocessable();
        $payload['id_token'] = 'verified-test-token';
        $payload['nonce'] = $this->nonce('register_pharmacy');
        $created = $this->postJson('/api/auth/google/register-pharmacy', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')->assertJsonMissingPath('data.token');
        $pharmacy = Pharmacy::where('pharmacy_custom_id', $created->json('data.pharmacy_id'))->firstOrFail();
        $this->assertFalse($pharmacy->is_active);
        $this->assertNull($pharmacy->pending_password);
        $this->assertTrue(Hash::check('StrongPassword123', $pharmacy->user->password));
        $login = ['id_token' => 'verified-test-token', 'nonce' => $this->nonce('login'), 'role' => 'pharmacy'];
        $this->postJson('/api/auth/google/login', $login)->assertUnauthorized();
        $pharmacy->forceFill(['is_active' => true])->save();
        $pharmacy->user->forceFill(['is_active' => true])->save();
        $login['nonce'] = $this->nonce('login');
        $login['role'] = 'patient';
        $this->postJson('/api/auth/google/login', $login)->assertUnauthorized();
        $this->assertSame(0, $pharmacy->user->tokens()->count());
        $login['role'] = 'pharmacy';
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12']);
        $login['nonce'] = $this->nonce('login');
        $this->postJson('/api/auth/google/login', $login)->assertOk()->assertJsonPath('data.user.role', 'pharmacy')
            ->assertJsonPath('data.user.pharmacy_id', $pharmacy->pharmacy_custom_id)->assertJsonStructure(['data' => ['token']]);
        $this->postJson('/api/auth/google/login', $login)->assertUnauthorized();
    }

    public function test_patient_google_registration_requires_terms_and_verified_matching_email(): void
    {
        $payload = ['name' => 'Patient', 'email' => 'patient@example.test', 'phone' => '0591234502', 'age' => 25,
            'id_token' => 'verified-test-token', 'nonce' => $this->nonce('register_patient')];
        $this->postJson('/api/auth/google/register-patient', $payload)->assertUnprocessable();
        $payload['terms_accepted'] = true;
        $payload['email'] = 'different@example.test';
        $this->postJson('/api/auth/google/register-patient', $payload)->assertUnprocessable();
        $payload['email'] = 'patient@example.test';
        $response = $this->postJson('/api/auth/google/register-patient', $payload)->assertCreated()
            ->assertJsonPath('data.user.role', 'patient')->assertJsonStructure(['data' => ['token']]);
        $this->assertTrue(User::findOrFail($response->json('data.user.id'))->hasRole('patient'));
    }

    public function test_pharmacy_recovery_requires_linked_google_and_revokes_existing_sessions(): void
    {
        $user = User::factory()->pharmacy()->create(['password' => Hash::make('OldPassword123')]);
        Pharmacy::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $user->createToken('existing');
        $payload = ['nonce' => $this->nonce('recover_pharmacy'), 'id_token' => 'verified-test-token',
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'];
        $this->postJson('/api/auth/google/recover-pharmacy', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
        DB::table('google_identities')->insert(['user_id' => $user->id, 'google_sub' => 'subject-123', 'created_at' => now(), 'updated_at' => now()]);
        $payload['nonce'] = $this->nonce('recover_pharmacy');
        $this->postJson('/api/auth/google/recover-pharmacy', $payload)->assertOk();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/google/recover-pharmacy', $payload)->assertUnprocessable();
    }

    public function test_public_challenge_cannot_create_authenticated_link_challenge(): void
    {
        $this->postJson('/api/auth/google/challenge', ['purpose' => 'link_pharmacy'])->assertUnprocessable();
    }
}
