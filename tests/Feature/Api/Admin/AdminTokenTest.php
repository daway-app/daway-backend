<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_gets_401(): void
    {
        $response = $this->getJson('/admin/maintenance/token');
        $response->assertUnauthorized();
    }

    public function test_patient_user_gets_403(): void
    {
        $patient = User::create([
            'name' => 'Patient User',
            'email' => 'patient@test.com',
            'phone' => '05910000001',
            'password' => Hash::make('password'),
        ]);
        $patient->role = 'patient';
        $patient->is_active = true;
        $patient->email_verified_at = now();
        $patient->phone_verified_at = now();
        $patient->save();
        $patient->syncRoles(['patient']);

        $this->actingAs($patient);

        $response = $this->getJson('/admin/maintenance/token');
        $response->assertForbidden();
    }

    public function test_pharmacy_user_gets_403(): void
    {
        $pharmacyUser = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharmacy@test.com',
            'phone' => '05910000002',
            'password' => Hash::make('password'),
        ]);
        $pharmacyUser->role = 'pharmacy';
        $pharmacyUser->is_active = true;
        $pharmacyUser->email_verified_at = now();
        $pharmacyUser->phone_verified_at = now();
        $pharmacyUser->save();
        $pharmacyUser->syncRoles(['pharmacy']);

        $this->actingAs($pharmacyUser);

        $response = $this->getJson('/admin/maintenance/token');
        $response->assertForbidden();
    }

    public function test_admin_web_session_gets_200_and_token(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'phone' => '05910000003',
            'password' => Hash::make('password'),
        ]);
        $admin->role = 'admin';
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->phone_verified_at = now();
        $admin->save();
        $admin->syncRoles(['admin']);

        $this->actingAs($admin);

        $response = $this->getJson('/admin/maintenance/token');
        $response->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['token']])
            ->assertJson([
                'success' => true,
                'message' => 'Admin token issued successfully.',
            ]);

        $this->assertNotEmpty($response->json('data.token'), 'Token should not be empty');
    }

    public function test_issued_token_works_on_admin_dry_run_endpoint(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin2@test.com',
            'phone' => '05910000004',
            'password' => Hash::make('password'),
        ]);
        $admin->role = 'admin';
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->phone_verified_at = now();
        $admin->save();
        $admin->syncRoles(['admin']);

        $this->actingAs($admin);

        $tokenResponse = $this->getJson('/admin/maintenance/token');
        $token = $tokenResponse->json('data.token');

        $this->assertNotEmpty($token, 'Token should be issued');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/admin/maintenance/pharmacy-moh-backfill/dry-run');

        $response->assertOk();
    }

    public function test_admin_token_endpoint_does_not_affect_sync_token(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin3@test.com',
            'phone' => '05910000005',
            'password' => Hash::make('password'),
        ]);
        $admin->role = 'admin';
        $admin->is_active = true;
        $admin->email_verified_at = now();
        $admin->phone_verified_at = now();
        $admin->save();
        $admin->syncRoles(['admin']);

        $this->actingAs($admin);

        $this->getJson('/admin/maintenance/token')->assertOk();

        // Verify sync token endpoint still requires pharmacy role
        $this->postJson('/api/sync/token')->assertForbidden();
    }
}
