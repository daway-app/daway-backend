<?php

namespace Tests\Feature\Web;

use Database\Seeders\AdminSeeder;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $this->seed(AdminSeeder::class);

        $response = $this->from('/login')->post('/login', [
            'identity' => 'admin@daway.com',
            'password' => 'Admin@12345',
            'account_type' => 'admin',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_with_wrong_password_returns_errors(): void
    {
        $this->seed(AdminSeeder::class);

        $response = $this->from('/login')->post('/login', [
            'identity' => 'admin@daway.com',
            'password' => 'wrong-password',
            'account_type' => 'admin',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('identity');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_too_many_attempts(): void
    {
        $response = null;

        for ($i = 0; $i < 6; $i++) {
            $response = $this->from('/login')->post('/login', [
                'identity' => 'admin@daway.com',
                'password' => 'wrong-password',
                'account_type' => 'admin',
            ]);
        }

        $this->assertNotNull($response);
        $response->assertStatus(429);
    }
}
