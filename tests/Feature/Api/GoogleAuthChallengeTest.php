<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Auth\GoogleAuthChallenge;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthChallengeTest extends TestCase
{
    public function test_nonce_is_hashed_and_can_only_be_consumed_once(): void
    {
        $service = app(GoogleAuthChallenge::class);
        $nonce = $service->issue('login');
        $this->assertSame(64, strlen($nonce));
        $this->assertSame(0, DB::table('google_auth_challenges')->where('nonce_hash', $nonce)->count());
        $service->consume($nonce, 'login');
        $this->expectException(RuntimeException::class);
        $service->consume($nonce, 'login');
    }

    public function test_link_challenge_is_bound_to_user_purpose_and_token(): void
    {
        $user = User::factory()->pharmacy()->create();
        $other = User::factory()->pharmacy()->create();
        $service = app(GoogleAuthChallenge::class);
        $nonce = $service->issue('link_pharmacy', $user->id, 42);
        foreach ([['login', $user->id, 42], ['link_pharmacy', $other->id, 42], ['link_pharmacy', $user->id, 43]] as [$purpose, $userId, $tokenId]) {
            try {
                $service->consume($nonce, $purpose, $userId, $tokenId);
                $this->fail('Challenge accepted in wrong context');
            } catch (RuntimeException $e) {
                $this->assertSame('Invalid or expired Google challenge.', $e->getMessage());
            }
        }
        $service->consume($nonce, 'link_pharmacy', $user->id, 42);
        $this->assertNotNull(DB::table('google_auth_challenges')->where('nonce_hash', hash('sha256', $nonce))->value('consumed_at'));
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $service = app(GoogleAuthChallenge::class);
        $nonce = $service->issue('recover_pharmacy');
        $this->travel(6)->minutes();
        $this->expectException(RuntimeException::class);
        $service->consume($nonce, 'recover_pharmacy');
    }
}
