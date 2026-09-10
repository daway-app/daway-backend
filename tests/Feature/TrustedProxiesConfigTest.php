<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxiesConfigTest extends TestCase
{
    public function test_trusted_proxies_defaults_to_empty_array_when_env_missing(): void
    {
        $proxies = config('trustedproxy.proxies');

        $this->assertIsArray(
            $proxies,
            'CONTRACT NOT YET LANDED: config("trustedproxy.proxies") is missing — the trustedproxy.php config file must exist.'
        );

        $this->assertCount(
            0,
            $proxies,
            'CONTRACT VIOLATION: config("trustedproxy.proxies") must default to an empty array when TRUSTED_PROXIES env is empty/unset, got: '.json_encode($proxies).'.'
        );
    }
}
