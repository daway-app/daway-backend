<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginRolePreservationTest extends TestCase
{
    public function test_failed_pharmacy_login_keeps_account_type_selected(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-WRONG1'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-WRONG1',
        ]);

        // بيانات غلط مع اختيار "صيدلية"
        $response = $this->post(route('login'), [
            'identity' => 'PH-WRONG1',
            'password' => 'wrong-password',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect();

        // المتابعة: يجب أن يظل النوع المختار "صيدلية" وليس الأدمن
        $followed = $this->get($response->headers->get('Location'));
        $followed->assertOk()
            ->assertSee('value="pharmacy" selected', false)
            ->assertDontSee('value="admin" selected', false);
    }

    public function test_failed_admin_login_keeps_account_type_selected(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-pass'),
        ]);

        $response = $this->post(route('login'), [
            'identity' => 'admin@example.com',
            'password' => 'wrong-password',
            'account_type' => 'admin',
        ]);

        $response->assertRedirect();

        $followed = $this->get($response->headers->get('Location'));
        $followed->assertOk()
            ->assertSee('value="admin" selected', false);
    }

    public function test_successful_pharmacy_login_redirects_to_dashboard(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('PH-OK1234'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-OK1234',
            'profile_completed_at' => now(),
        ]);

        $response = $this->post(route('login'), [
            'identity' => 'PH-OK1234',
            'password' => 'PH-OK1234',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));
        $this->assertAuthenticatedAs($user);
    }
}
