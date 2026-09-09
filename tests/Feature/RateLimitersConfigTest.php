<?php

namespace Tests\Feature;

use Tests\TestCase;

class RateLimitersConfigTest extends TestCase
{
    public function test_pharmacy_login_is_throttled_on_sixth_attempt_from_same_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login/pharmacy', [
                'pharmacy_id' => 'PH-DOES-NOT-EXIST-9999',
                'password' => 'wrong-password',
            ]);

            $this->assertNotSame(
                429,
                $response->status(),
                "Attempt #{$i} should not be throttled (throttle:login is 5/min)."
            );
        }

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-DOES-NOT-EXIST-9999',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
