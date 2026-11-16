<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

class GooglePharmacyLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $verifier = Mockery::mock(GoogleIdentityVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(['sub' => 'verified-google-sub', 'email' => 'x@example.test', 'email_verified' => true]);
        $this->app->instance(GoogleIdentityVerifier::class, $verifier);
    }

    private function account(): array
    {
        $user = User::factory()->pharmacy()->create(['password' => Hash::make('CurrentPassword123')]);
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        return [$user, $pharmacy, $user->createToken('test')->plainTextToken];
    }

    public function test_active_pharmacy_links_verified_subject_once(): void
    {
        [$user, , $token] = $this->account();
        $nonce = $this->withToken($token)->postJson('/api/pharmacy/google/challenge')->assertOk()->json('nonce');
        $input = ['nonce' => $nonce, 'id_token' => 'verified-test-token', 'current_password' => 'CurrentPassword123'];
        $this->withToken($token)->postJson('/api/pharmacy/google/link', $input)->assertOk();
        $this->assertDatabaseHas('google_identities', ['user_id' => $user->id, 'google_sub' => 'verified-google-sub', 'email' => null]);
        $this->withToken($token)->postJson('/api/pharmacy/google/link', $input)->assertUnprocessable();
        $this->assertSame(1, DB::table('google_identities')->where('user_id', $user->id)->count());
    }

    public function test_wrong_password_and_wrong_session_cannot_link(): void
    {
        [$user, , $firstToken] = $this->account();
        $secondToken = $user->createToken('second')->plainTextToken;
        $nonce = $this->withToken($firstToken)->postJson('/api/pharmacy/google/challenge')->assertOk()->json('nonce');
        $input = ['nonce' => $nonce, 'id_token' => 'verified-test-token', 'current_password' => 'wrong'];
        $this->withToken($firstToken)->postJson('/api/pharmacy/google/link', $input)->assertForbidden();
        $input['current_password'] = 'CurrentPassword123';
        // Laravel caches guards between requests within a test process.
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->postJson('/api/pharmacy/google/link', $input)->assertUnprocessable();
        $this->assertSame(0, DB::table('google_identities')->count());
    }

    public function test_google_subject_cannot_link_to_two_pharmacies(): void
    {
        [, , $first] = $this->account();
        [, , $second] = $this->account();
        foreach ([$first, $second] as $index => $token) {
            $this->app['auth']->forgetGuards();
            $nonce = $this->withToken($token)->postJson('/api/pharmacy/google/challenge')->assertOk()->json('nonce');
            $response = $this->withToken($token)->postJson('/api/pharmacy/google/link',
                ['nonce' => $nonce, 'id_token' => 'verified-test-token', 'current_password' => 'CurrentPassword123']);
            $index === 0 ? $response->assertOk() : $response->assertUnprocessable();
        }
        $this->assertSame(1, DB::table('google_identities')->count());
    }

    public function test_inactive_or_closed_pharmacy_cannot_get_challenge(): void
    {
        [, $pharmacy, $token] = $this->account();
        $pharmacy->forceFill(['is_active' => false])->save();
        $this->withToken($token)->postJson('/api/pharmacy/google/challenge')->assertForbidden();
    }

    public function test_password_guesses_on_google_link_are_rate_limited(): void
    {
        [, , $token] = $this->account();
        $payload = ['nonce' => str_repeat('a', 64), 'id_token' => 'verified-test-token', 'current_password' => 'incorrect'];
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
                ->withToken($token)->postJson('/api/pharmacy/google/link', $payload)->assertForbidden();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->withToken($token)->postJson('/api/pharmacy/google/link', $payload)->assertStatus(429);
        $this->assertSame(0, DB::table('google_identities')->count());
    }
}
