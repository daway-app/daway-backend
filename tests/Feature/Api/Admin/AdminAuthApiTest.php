<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Pharmacy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * لوحة الأدمن API — المصادقة والصلاحيات.
 *
 * هذه أهم اختبارات الميزة: كل مسار `/api/admin/*` يجب أن يرفض غير الأدمن،
 * بغضّ النظر عن صحة التوكن. لو تسرّب مسار واحد، يصبح الوصول لبيانات كل
 * الصيدليات والمرضى ممكناً لصيدلية واحدة.
 */
class AdminAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(string $role, array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make('password123'),
        ], $overrides));

        $user->role = $role;
        $user->is_active = $overrides['is_active'] ?? true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    // ---------------------------------------------------------------------
    // تسجيل الدخول
    // ---------------------------------------------------------------------

    public function test_admin_can_login_and_receives_a_token(): void
    {
        $admin = $this->makeUser('admin', ['email' => 'admin@daway.test']);

        $response = $this->postJson('/api/login/admin', [
            'email' => 'admin@daway.test',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_a_pharmacy_account_cannot_use_the_admin_login(): void
    {
        // مسار الأدمن يجب ألا يصدر توكن لأي دور آخر — حتى لو عرف كلمة المرور.
        $this->makeUser('pharmacy', ['email' => 'ph@daway.test']);

        $this->postJson('/api/login/admin', [
            'email' => 'ph@daway.test',
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_a_patient_account_cannot_use_the_admin_login(): void
    {
        $this->makeUser('patient', ['email' => 'pt@daway.test']);

        $this->postJson('/api/login/admin', [
            'email' => 'pt@daway.test',
            'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_wrong_password_is_401_with_a_generic_message(): void
    {
        $this->makeUser('admin', ['email' => 'admin@daway.test']);

        $response = $this->postJson('/api/login/admin', [
            'email' => 'admin@daway.test',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();
        // نفس الرسالة لحالة "بريد غير موجود" و"كلمة مرور خاطئة" — لا كشف وجود الحساب
        $this->assertSame('بيانات الاعتماد غير صحيحة.', $response->json('message'));
    }

    public function test_unknown_email_gives_the_same_message_as_a_wrong_password(): void
    {
        $this->makeUser('admin', ['email' => 'admin@daway.test']);

        $response = $this->postJson('/api/login/admin', [
            'email' => 'nobody@daway.test',
            'password' => 'password123',
        ]);

        $response->assertUnauthorized();
        $this->assertSame('بيانات الاعتماد غير صحيحة.', $response->json('message'));
    }

    public function test_an_inactive_admin_is_rejected_after_a_correct_password(): void
    {
        $this->makeUser('admin', ['email' => 'admin@daway.test', 'is_active' => false]);

        $this->postJson('/api/login/admin', [
            'email' => 'admin@daway.test',
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_login_validates_its_input(): void
    {
        $this->postJson('/api/login/admin', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ---------------------------------------------------------------------
    // حراسة كل مسارات /api/admin/*
    // ---------------------------------------------------------------------

    /**
     * كل عائلة مسارات يجب أن ترفض الزائر (401) وتسمح للأدمن (200).
     */
    #[DataProvider('adminEndpoints')]
    public function test_guests_are_unauthorized(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    #[DataProvider('adminEndpoints')]
    public function test_non_admin_roles_are_forbidden(string $method, string $uri): void
    {
        foreach (['pharmacy', 'patient'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user, 'sanctum');

            $this->json($method, $uri)->assertForbidden();
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminEndpoints(): array
    {
        return [
            'dashboard' => ['GET', '/api/admin/dashboard'],
            'pharmacies' => ['GET', '/api/admin/pharmacies'],
            'medicines' => ['GET', '/api/admin/medicines'],
            'medicine requests' => ['GET', '/api/admin/medicine-requests'],
            'users' => ['GET', '/api/admin/users'],
            'patients' => ['GET', '/api/admin/patients'],
            'inventory' => ['GET', '/api/admin/inventory'],
            'categories' => ['GET', '/api/admin/categories'],
            'logs' => ['GET', '/api/admin/logs'],
            'settings' => ['GET', '/api/admin/settings'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function test_admin_reaches_every_endpoint(string $method, string $uri): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'sanctum');

        $this->json($method, $uri)->assertOk();
    }

    // ---------------------------------------------------------------------
    // حمايات الكتابة الحسّاسة
    // ---------------------------------------------------------------------

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'sanctum');

        $this->patchJson("/api/admin/users/{$admin->id}/toggle-status")
            ->assertStatus(422);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'sanctum');

        $this->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'phone' => $admin->phone,
            'role' => 'patient',
            'status' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_cannot_change_the_role_of_a_pharmacy_linked_account(): void
    {
        $admin = $this->makeUser('admin');
        $pharmacyUser = $this->makeUser('pharmacy');

        $pharmacy = new Pharmacy(['pharmacy_name' => 'Test Pharmacy']);
        $pharmacy->user_id = $pharmacyUser->id;
        $pharmacy->pharmacy_custom_id = 'PH-TEST';
        $pharmacy->is_active = true;
        $pharmacy->save();

        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/admin/users/{$pharmacyUser->id}", [
            'name' => $pharmacyUser->name,
            'email' => $pharmacyUser->email,
            'phone' => $pharmacyUser->phone,
            'role' => 'patient',
            'status' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_toggling_a_pharmacy_linked_user_syncs_the_pharmacy_row(): void
    {
        $admin = $this->makeUser('admin');
        $pharmacyUser = $this->makeUser('pharmacy');

        $pharmacy = new Pharmacy(['pharmacy_name' => 'Sync Pharmacy']);
        $pharmacy->user_id = $pharmacyUser->id;
        $pharmacy->pharmacy_custom_id = 'PH-SYNC';
        $pharmacy->is_active = true;
        $pharmacy->save();

        $this->actingAs($admin, 'sanctum');

        $this->patchJson("/api/admin/users/{$pharmacyUser->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $pharmacy->fresh()->is_active);
    }
}
