<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

class PharmacyLoginTest extends TestCase
{
    public function test_pharmacy_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-LOGIN1',
        ]);

        $response = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-LOGIN1',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'pharmacy_id', 'role'],
                    'token',
                ],
            ])
            ->assertJsonPath('data.user.pharmacy_id', 'PH-LOGIN1')
            ->assertJsonPath('data.user.role', 'pharmacy');
    }

    public function test_pharmacy_login_rejects_wrong_password(): void
    {
        $user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-LOGIN2',
        ]);

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-LOGIN2',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_pharmacy_login_rejects_inactive_user(): void
    {
        $user = User::factory()->pharmacy()->create(['is_active' => false]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-LOGIN3',
        ]);

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-LOGIN3',
            'password' => 'password',
        ])->assertStatus(403);
    }
}
