<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminInitialPasswordEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(bool $mustChange = true, string $password = 'OldPass123'): User
    {
        $user = User::create([
            'name' => 'Initial Admin',
            'email' => 'initial-admin-'.uniqid().'@test.com',
            'phone' => '0599'.random_int(100000, 999999),
            'password' => Hash::make($password),
        ]);
        $user->role = 'admin';
        $user->is_active = true;
        $user->must_change_password = $mustChange;
        $user->save();
        $user->syncRoles(['admin']);

        return $user;
    }

    public function test_admin_with_initial_password_can_login_and_receives_the_flag(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/login/admin', [
            'email' => $admin->email,
            'password' => 'OldPass123',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.must_change_password', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role', 'must_change_password']]]);
    }

    public function test_initial_password_flag_blocks_all_three_admin_route_groups(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('initial-admin-test')->plainTextToken;

        foreach ([
            '/api/admin/dashboard',
            '/api/admin/maintenance/pharmacy-moh-backfill/dry-run',
            '/api/admin/categories/1/medicines',
        ] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson($uri)
                ->assertForbidden()
                ->assertExactJson([
                    'success' => false,
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                    'message' => 'يجب تغيير كلمة المرور الأولية قبل استخدام لوحة الإدارة.',
                ]);
        }
    }

    public function test_every_admin_api_route_is_gated_except_login_and_password_change(): void
    {
        $adminRoutes = array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/admin/'),
        ));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $uri = $route->uri();

            $this->assertContains('auth:sanctum', $middleware, "{$uri} must require Sanctum auth.");
            $this->assertContains('role:admin', $middleware, "{$uri} must require the admin role.");

            if ($uri === 'api/admin/change-password') {
                $this->assertNotContains('admin.password.changed', $middleware);

                continue;
            }

            $this->assertContains(
                'admin.password.changed',
                $middleware,
                "{$uri} is an Admin API route without the initial-password gate.",
            );
        }
    }

    public function test_gate_reads_current_database_value_and_old_token_cannot_bypass_it(): void
    {
        $admin = $this->admin(mustChange: false);
        $oldToken = $admin->createToken('old-admin-token')->plainTextToken;

        User::query()->whereKey($admin->id)->update(['must_change_password' => true]);
        $this->app['auth']->forgetGuards();

        $this->withToken($oldToken)->getJson('/api/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_change_password_remains_available_and_rejects_a_wrong_current_password(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('initial-admin-test')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ])->assertStatus(422);

        $this->assertTrue($admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('OldPass123', $admin->fresh()->password));
    }

    public function test_password_change_rejects_reuse_then_clears_flag_and_revokes_all_tokens(): void
    {
        $admin = $this->admin();
        $oldToken = $admin->createToken('old-admin-token')->plainTextToken;
        $secondToken = $admin->createToken('second-old-admin-token')->plainTextToken;

        $this->withToken($oldToken)->postJson('/api/admin/change-password', [
            'current_password' => 'OldPass123',
            'password' => 'OldPass123',
            'password_confirmation' => 'OldPass123',
        ])->assertStatus(422);
        $this->assertTrue($admin->fresh()->must_change_password);
        $this->assertSame(2, $admin->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->postJson('/api/admin/change-password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ])->assertOk();

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPass456', $admin->fresh()->password));
        $this->assertSame(0, $admin->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/admin/dashboard')->assertUnauthorized();

        $this->postJson('/api/login/admin', [
            'email' => $admin->email,
            'password' => 'NewPass456',
        ])->assertOk()->assertJsonPath('data.user.must_change_password', false);
    }

    public function test_admin_without_initial_password_flag_keeps_admin_access(): void
    {
        $admin = $this->admin(mustChange: false);
        $token = $admin->createToken('regular-admin')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();
        $this->assertFalse($admin->fresh()->must_change_password);
    }

    public function test_other_roles_keep_their_own_login_flow(): void
    {
        foreach (['pharmacy', 'patient', 'rider'] as $role) {
            $user = User::create([
                'name' => ucfirst($role),
                'email' => $role.'-'.uniqid().'@test.com',
                'phone' => '0599'.random_int(100000, 999999),
                'password' => Hash::make('OldPass123'),
            ]);
            $user->role = $role;
            $user->is_active = true;
            $user->must_change_password = true;
            $user->save();
            $user->syncRoles([$role]);

            $token = $user->createToken($role.'-test')->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/admin/dashboard')->assertForbidden()
                ->assertJsonMissing(['code' => 'PASSWORD_CHANGE_REQUIRED']);
        }
    }
}
