<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قواعد أعمال إدارة المستخدمين من الأدمن — **منقولة من
 * `tests/Feature/Web/AdminCrudAccessTest.php`** الذي حُذف مع Blade.
 *
 * ── لماذا هذا الملف؟ ─────────────────────────────────────────────
 * الاختبار المحذوف لم يكن «رسم صفحات» فقط: كان يحمي ثلاث قواعد حقيقية
 * كانت ستضيع بلا بديل:
 *  1. تعطيل/تفعيل مستخدم صيدلية يزامن `pharmacies.is_active` (القاعدة 7 في
 *     AGENTS.md: نظام الدور المزدوج يبقى متسقًا).
 *  2. تعديل حالة المستخدم من نموذج التحرير يزامن حالة الصيدلية أيضًا.
 *  3. لا يمكن تغيير دور حساب مرتبط بصيدلية (وإلا بقي صف صيدلية بلا مالك).
 *
 * كل تأكيد هنا على مسار API فعلي — لا استعادة لأي شيء من Blade.
 */
final class AdminUserManagementApiTest extends TestCase
{
    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyOwner(): array
    {
        $user = User::factory()->pharmacy()->create([
            'phone' => '0599000111',
            'is_active' => true,
        ]);
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return [$user, $pharmacy];
    }

    public function test_toggling_user_status_keeps_the_pharmacy_in_sync(): void
    {
        $this->admin();
        [$user, $pharmacy] = $this->pharmacyOwner();

        $this->patchJson('/api/admin/users/'.$user->id.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertFalse((bool) $pharmacy->fresh()->is_active, 'تعطيل المستخدم يجب أن يعطّل صيدليته');

        // والعكس: تفعيل المستخدم يفعّل الصيدلية
        $this->patchJson('/api/admin/users/'.$user->id.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue((bool) $pharmacy->fresh()->is_active);
    }

    public function test_editing_user_status_syncs_the_pharmacy(): void
    {
        $this->admin();
        [$user, $pharmacy] = $this->pharmacyOwner();

        $this->patchJson('/api/admin/users/'.$user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => 'pharmacy',
            'status' => false,
        ])->assertOk();

        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertFalse((bool) $pharmacy->fresh()->is_active, 'تعديل حالة المستخدم يجب أن يزامن الصيدلية');
    }

    public function test_role_cannot_be_changed_for_an_account_linked_to_a_pharmacy(): void
    {
        $this->admin();
        [$user, $pharmacy] = $this->pharmacyOwner();

        $this->patchJson('/api/admin/users/'.$user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => 'patient',
            'status' => true,
        ])->assertStatus(422)->assertJsonPath('success', false);

        // الدور لم يتغيّر، والصيدلية ما زالت مرتبطة بمالكها
        $this->assertSame('pharmacy', $user->fresh()->role);
        $this->assertSame($user->id, $pharmacy->fresh()->user_id);
    }

    public function test_role_can_be_changed_for_an_account_without_a_pharmacy_and_spatie_stays_in_sync(): void
    {
        $this->admin();
        $user = User::factory()->patient()->create([
            'phone' => '0599000222',
            'is_active' => true,
        ]);

        $this->patchJson('/api/admin/users/'.$user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => 'admin',
            'status' => true,
        ])->assertOk();

        $user->refresh();
        $this->assertSame('admin', $user->role);
        // القاعدة 7: users.role وSpatie roles معًا
        $this->assertTrue($user->hasRole('admin'), 'Spatie role يجب أن يُزامَن مع users.role');
        $this->assertFalse($user->hasRole('patient'));
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = $this->admin();

        $this->patchJson('/api/admin/users/'.$admin->id.'/toggle-status')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_non_admin_cannot_toggle_user_status(): void
    {
        [$user] = $this->pharmacyOwner();
        $other = User::factory()->pharmacy()->create();

        Sanctum::actingAs($other);
        $this->patchJson('/api/admin/users/'.$user->id.'/toggle-status')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertTrue((bool) $user->fresh()->is_active);
    }
}
