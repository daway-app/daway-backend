<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigCacheSafetyTest extends TestCase
{
    public function test_services_firebase_config_group_exists(): void
    {
        $firebase = config('services.firebase');

        $this->assertIsArray(
            $firebase,
            'CONTRACT NOT YET LANDED: config("services.firebase") is missing — the INFRA agent must add a "firebase" group to config/services.php (keys: api_key, credentials, project_id) so "config:cache" does not strip null env-based keys.'
        );
    }

    public function test_services_firebase_api_key_key_exists_in_config(): void
    {
        $firebase = config('services.firebase') ?? [];

        $this->assertArrayHasKey(
            'api_key',
            $firebase,
            'CONTRACT NOT YET LANDED: config("services.firebase") has no "api_key" key — INFRA agent must add it to config/services.php. The value may be null when the env var is empty; the key itself must exist.'
        );
    }

    public function test_services_moh_has_ssl_verify_and_url_keys(): void
    {
        $moh = config('services.moh') ?? [];

        $this->assertArrayHasKey(
            'ssl_verify',
            $moh,
            'CONTRACT NOT YET LANDED: config("services.moh") is missing the "ssl_verify" key — INFRA agent must add it to config/services.php (e.g. \'ssl_verify\' => env(\'MOH_SSL_VERIFY\', true)).'
        );

        $this->assertArrayHasKey(
            'products_url',
            $moh,
            'CONTRACT NOT YET LANDED: config("services.moh") is missing the "products_url" key — INFRA agent must add it to config/services.php.'
        );

        $this->assertArrayHasKey(
            'prices_url',
            $moh,
            'CONTRACT NOT YET LANDED: config("services.moh") is missing the "prices_url" key — INFRA agent must add it to config/services.php.'
        );
    }

    public function test_trusted_proxies_config_file_is_an_array(): void
    {
        $proxies = config('trustedproxy.proxies');

        $this->assertIsArray(
            $proxies,
            'CONTRACT NOT YET LANDED: config("trustedproxy.proxies") is missing — the trustedproxy.php config file must exist so TrustProxies reads proxies at request time (config-cache safe).'
        );
    }
}
