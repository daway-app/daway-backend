<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تغيير كلمة المرور عبر API الموبايل — أصبح اختيارياً.
 *
 * الصيدلية اختارت كلمة مرورها عند التسجيل، لذا:
 * - الطلب بكلمة مرور جديدة = تغيير فعلي + إبطال التوكنات
 * - الطلب بلا حقول = إلغاء إلزامية التغيير فقط (must_change_password) دون مساس بكلمة المرور
 */
class PharmacyChangePasswordOptionalApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPharmacy(array $userAttributes = []): User
    {
        $user = User::factory()->pharmacy()->create($userAttributes);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_change_password_with_new_password_works(): void
    {
        $user = $this->actingAsPharmacy(['password' => Hash::make('old-pass-123')]);

        $this->postJson('/api/pharmacy/change-password', [
            'current_password' => 'old-pass-123',
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('new-pass-123', $user->password));
        $this->assertFalse((bool) $user->must_change_password);
    }

    public function test_empty_request_clears_must_change_password_without_touching_password(): void
    {
        $user = $this->actingAsPharmacy([
            'password' => Hash::make('kept-pass-123'),
            'must_change_password' => true,
        ]);

        $this->postJson('/api/pharmacy/change-password', [])->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('kept-pass-123', $user->password), 'كلمة المرور يجب ألا تتغير');
        $this->assertFalse((bool) $user->must_change_password, 'إلزامية التغيير يجب أن تُلغى');
    }

    public function test_password_without_current_password_is_rejected(): void
    {
        $user = $this->actingAsPharmacy(['password' => Hash::make('old-pass-123')]);

        $this->postJson('/api/pharmacy/change-password', [
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-pass-123', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->actingAsPharmacy(['password' => Hash::make('old-pass-123')]);

        $this->postJson('/api/pharmacy/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-pass-123', $user->fresh()->password));
    }
}
