<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_healthz_returns_200_json_with_status_and_fcm_keys_without_auth(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'fcm',
            ]);
    }

    public function test_healthz_is_publicly_accessible_without_authentication(): void
    {
        $response = $this->get('/healthz');

        $this->assertSame(200, $response->status());
    }

    public function test_up_builtin_health_endpoint_returns_200(): void
    {
        $this->get('/up')->assertOk();
    }
}
