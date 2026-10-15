<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\PharmacyPasswordResetRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PharmacyPasswordResetRequestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role, string $phone): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Test',
            'email' => $role.'-'.uniqid().'@example.test',
            'phone' => $phone,
            'password' => Hash::make('old-password'),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function pharmacy(): Pharmacy
    {
        $user = $this->user('pharmacy', '0599123456');

        return Pharmacy::forceCreate([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-TEST1',
            'pharmacy_name' => 'صيدلية الاختبار',
            'is_active' => true,
        ]);
    }

    public function test_public_request_uses_generic_response_and_only_records_matching_details(): void
    {
        $pharmacy = $this->pharmacy();

        $this->postJson('/api/pharmacy/password-reset-requests', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'phone' => '0599123456',
        ])->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data');

        $this->assertDatabaseHas('pharmacy_password_reset_requests', [
            'pharmacy_id' => $pharmacy->id,
            'status' => 'pending',
        ]);

        // بيانات لا تطابق أي صيدلية: نفس الردّ بالضبط، ولا سجلّ جديد —
        // هذا ما يمنع استخدام المسار لتعداد الحسابات.
        $this->postJson('/api/pharmacy/password-reset-requests', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'phone' => '0599000000',
        ])->assertStatus(202)
            ->assertJsonPath('message', 'إذا كانت بيانات الصيدلية مطابقة، فسيُراجع طلب الاستعادة من الإدارة.');

        $this->assertDatabaseCount('pharmacy_password_reset_requests', 1);
    }

    public function test_only_admin_can_review_and_approve_request(): void
    {
        $pharmacy = $this->pharmacy();
        $resetRequest = PharmacyPasswordResetRequest::create([
            'pharmacy_id' => $pharmacy->id,
            'status' => 'pending',
        ]);

        $this->getJson('/api/admin/pharmacy-password-reset-requests')->assertUnauthorized();

        $admin = $this->user('admin', '0599765432');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pharmacy-password-reset-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resetRequest->id);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/pharmacy-password-reset-requests/{$resetRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true);

        $newPassword = $response->json('data.credentials.password');
        $this->assertIsString($newPassword);
        $this->assertNotSame('old-password', $newPassword);
        $this->assertTrue(Hash::check($newPassword, $pharmacy->user->fresh()->password));
        $this->assertDatabaseHas('pharmacy_password_reset_requests', [
            'id' => $resetRequest->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
    }
}
