<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacyPasswordChangeTest extends TestCase
{
    public function test_pharmacy_created_by_admin_has_default_password_equal_to_id(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Test Pharmacy',
        ]);

        $response->assertRedirect(route('pharmacies.index'));

        $pharmacy = Pharmacy::where('pharmacy_name', 'Test Pharmacy')->first();
        $this->assertNotNull($pharmacy);
        $this->assertNull($pharmacy->phone_number);
        $this->assertNull($pharmacy->address);
        $this->assertNull($pharmacy->profile_completed_at);

        $user = $pharmacy->user;
        $this->assertNull($user->email);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check($pharmacy->pharmacy_custom_id, $user->password));
    }

    public function test_pharmacy_login_returns_must_change_password_flag(): void
    {
        $user = User::factory()->pharmacy()->create([
            'must_change_password' => true,
            'password' => Hash::make('PH-FORCE1'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-FORCE1',
        ]);

        $response = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-FORCE1',
            'password' => 'PH-FORCE1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_pharmacy_can_change_default_password_via_api(): void
    {
        $user = User::factory()->pharmacy()->create([
            'must_change_password' => true,
            'password' => Hash::make('PH-FORCE2'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-FORCE2',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pharmacy/change-password', [
                'current_password' => 'PH-FORCE2',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_pharmacy_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->pharmacy()->create([
            'must_change_password' => true,
            'password' => Hash::make('PH-FORCE3'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-FORCE3',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pharmacy/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_patient_cannot_use_pharmacy_change_password_endpoint(): void
    {
        $user = User::factory()->patient()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/pharmacy/change-password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertStatus(403);
    }
}
