<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H4: rate-limit على /api/refresh-token (30 طلب/دقيقة).
 */
class RefreshTokenRateLimitTest extends TestCase
{
    public function test_refresh_token_succeeds_under_limit(): void
    {
        $user = User::factory()->patient()->create();
        Sanctum::actingAs($user);

        // 3 طلبات متتالية (أقل من 30) كلها تنجح.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/refresh-token')->assertOk();
        }
    }

    public function test_refresh_token_rate_limit_kicks_in_after_threshold(): void
    {
        $user = User::factory()->patient()->create();
        Sanctum::actingAs($user);

        // H4: نرسل 31 طلباً متتالياً — يجب أن يُرفض الأخير بـ 429.
        // (الحد 30/دقيقة، الطلب 31 = 429).
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/refresh-token');
        }

        $this->postJson('/api/refresh-token')
            ->assertStatus(429);
    }
}
