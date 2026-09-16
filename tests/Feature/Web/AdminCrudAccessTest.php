<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * H-14: admin web CRUD — كانت بصفر اختبارات (أخطر فجوة تغطية).
 * كل مسارات admin محمية بـ role:admin — أي انحدار middleware ينكشف هنا.
 */
class AdminCrudAccessTest extends TestCase
{
    public function test_guest_is_redirected_from_all_admin_pages(): void
    {
        foreach (['/pharmacies', '/medicines', '/users', '/patients', '/inventory', '/settings', '/logs'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_patient_cannot_access_admin_pages(): void
    {
        $this->actingAs(User::factory()->patient()->create());

        foreach (['/pharmacies', '/medicines', '/users'] as $uri) {
            // EnsureRole web: غير الأدمن يُعاد لتطبيقه (302) — هذا هو العقد
            $this->get($uri)->assertRedirect();
        }
    }

    public function test_pharmacy_cannot_access_admin_pages(): void
    {
        $this->actingAs(User::factory()->pharmacy()->create());

        foreach (['/pharmacies', '/medicines', '/users'] as $uri) {
            $this->get($uri)->assertRedirect();
        }
    }

    public function test_admin_can_view_all_admin_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['/pharmacies', '/medicines', '/users', '/patients', '/inventory', '/settings'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_admin_can_toggle_user_status(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->patient()->create(['is_active' => true]);
        $this->actingAs($admin);

        $this->patch(route('users.toggleStatus', $target))->assertOk();

        $target->refresh();
        $this->assertFalse($target->is_active);
    }

    public function test_admin_can_toggle_pharmacy_status_and_user_stays_in_sync(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->pharmacy()->create(['is_active' => true]);
        $pharmacy = Pharmacy::factory()->create(['user_id' => $owner->id, 'is_active' => true]);
        $this->actingAs($admin);

        $this->patch(route('pharmacies.toggleStatus', $pharmacy))->assertRedirect();

        $pharmacy->refresh();
        $owner->refresh();
        $this->assertFalse($pharmacy->is_active);
        $this->assertFalse($owner->is_active, 'C1: users.is_active يبقى متزامناً مع pharmacies.is_active');
    }

    public function test_admin_can_create_medicine_in_global_catalog(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('medicines.store'), [
            'name_ar' => 'بانادول تيست-'.uniqid(),
            'active_ingredient' => 'Paracetamol',
        ])->assertRedirect();

        $this->assertDatabaseHas('medicines', ['active_ingredient' => 'Paracetamol']);
    }

    public function test_patient_cannot_toggle_users(): void
    {
        $this->actingAs(User::factory()->patient()->create());
        $target = User::factory()->patient()->create();

        // EnsureRole web → redirect (302) وليس 403
        $this->patch(route('users.toggleStatus', $target))->assertRedirect();
    }

    /**
     * 🔴 نموذج تعديل المستخدم كان يكتب users.is_active **بلا** مزامنة pharmacies.is_active،
     * بينما زر التبديل (toggleStatus) يزامن. مسارَان يكتبان نفس الحقل بسلوك مختلف
     * ⇒ تعطيل صامت: الأدمن يعطّل الحساب فيظن أنه انتهى، والصيدلية تبقى مفعّلة.
     */
    public function test_editing_user_status_via_form_syncs_pharmacy_is_active(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->pharmacy()->create(['is_active' => true]);
        $pharmacy = Pharmacy::factory()->create(['user_id' => $owner->id, 'is_active' => true]);
        $this->actingAs($admin);

        $this->put(route('users.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'role' => 'pharmacy',
            'status' => '0',
        ])->assertRedirect(route('users.index'));

        $owner->refresh();
        $pharmacy->refresh();

        $this->assertFalse($owner->is_active);
        $this->assertFalse(
            $pharmacy->is_active,
            'C1-فورم: pharmacies.is_active يجب أن يبقى متزامناً مع users.is_active'
        );
    }

    /**
     * 🔴 تغيير دور حساب مرتبط بصيدلية يكسره تماماً: الدخول ينجح ثم يردّه EnsureRole
     * إلى صفحة الدخول ⇒ يبدو كأن الدخول توقف فجأة. الحارس يمنعه برسالة صريحة.
     */
    public function test_admin_cannot_change_role_of_account_linked_to_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($admin);

        $this->put(route('users.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'role' => 'patient',
            'status' => '1',
        ])->assertSessionHasErrors('role');

        $owner->refresh();
        $this->assertSame('pharmacy', $owner->role, 'دور حساب مرتبط بصيدلية لا يُغيَّر');
    }

    /**
     * العكس: حساب بلا صيدلية يتغيّر دوره بحرية (الحارس لا يوسّع نطاقه بلا داعٍ).
     */
    public function test_admin_can_still_change_role_of_account_without_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->patient()->create();
        $this->actingAs($admin);

        $this->put(route('users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'phone' => $target->phone,
            'role' => 'admin',
            'status' => '1',
        ])->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('admin', $target->role);
    }
}
