<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * لوحة الأدمن API — الملف الشخصي وتغيير كلمة المرور.
 *
 * يحرس ثلاثة أخطاء قاتلة:
 *   1. كلمة مرور حالية خاطئة **يجب** أن تُرفض (نقطة القتل: حارس الجلسة المسروقة).
 *   2. نفس كلمة المرور كقيمة جديدة **يجب** أن تُرفض.
 *   3. النجاح **يجب** أن يُبطل التوكنات (وإلا بقي توكن قديم صالحًا بلا حد).
 * ويحرس عقد استجابة `/profile`: `avatar_url` (لا مسار خام)، والدور.
 */
class AdminProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(string $password = 'OldPass123'): User
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make($password),
        ]);
        $user->role = 'admin';
        $user->is_active = true;
        $user->save();
        $user->syncRoles(['admin']);

        return $user;
    }

    public function test_profile_returns_the_admin_with_avatar_url_not_raw_path(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertArrayHasKey('avatar_url', $response->json('data.user'));
        $this->assertArrayNotHasKey('avatar', $response->json('data.user'), 'المسار الخام يجب ألا يتسرّب.');
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        $admin = $this->admin('OldPass123');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/change-password', [
            'current_password' => 'WRONG-password',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertStatus(422);
        // كلمة المرور لم تتغيّر.
        $this->assertTrue(Hash::check('OldPass123', $admin->fresh()->password));
    }

    public function test_change_password_rejects_reusing_the_same_password(): void
    {
        $admin = $this->admin('SamePass123');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/change-password', [
            'current_password' => 'SamePass123',
            'password' => 'SamePass123',
            'password_confirmation' => 'SamePass123',
        ]);

        $response->assertStatus(422);
    }

    public function test_change_password_succeeds_and_invalidates_tokens(): void
    {
        $admin = $this->admin('OldPass123');
        $token = $admin->createToken('test-device')->plainTextToken;

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/change-password', [
            'current_password' => 'OldPass123',
            'password' => 'BrandNew789',
            'password_confirmation' => 'BrandNew789',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        // كلمة المرور الجديدة فعّالة.
        $this->assertTrue(Hash::check('BrandNew789', $admin->fresh()->password));
        $this->assertFalse($admin->fresh()->must_change_password);

        // كل التوكنات أُبطلت (H-13).
        $this->assertSame(0, $admin->fresh()->tokens()->count());
    }

    public function test_a_very_short_new_password_is_rejected(): void
    {
        $admin = $this->admin('OldPass123');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/change-password', [
            'current_password' => 'OldPass123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
    }
}
