<?php

namespace Tests\Feature\Security;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M-4: تزويد الصيدليات من الأدمن — كلمة مرور أولية عشوائية (غير مساوية للمعرف)
 * مع إلزام تغييرها عند أول دخول.
 *
 * ── تحويل بعد حذف واجهة Blade ─────────────────────────────────────
 * كان الملف يتحقق من `session('delivered_*)` ومن **HTML** صندوق بيانات
 * الدخول. الواجهة حُذفت، فصار العقد هو **استجابة API**:
 * `POST /api/admin/pharmacies` و`PATCH .../reset-credentials` و
 * `PATCH .../toggle-status` تعيد `data.credentials` في JSON.
 *
 * 🔴 ما حُذف عمدًا: تأكيد «الصندوق يُرسم في HTML» — لا صفحة Blade بعد
 * اليوم. مكافئه الحالي: التأكد أن بيانات الدخول تُعاد فعليًا في الاستجابة
 * (لا تُولَّد وتُفقد)، وهو ما تفعله الاختبارات أدناه.
 */
class PharmacyProvisioningTest extends TestCase
{
    public function test_admin_provisions_pharmacy_with_initial_password(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/pharmacies', [
            'pharmacy_name' => 'Provision Test Pharmacy',
        ])->assertCreated();

        $initialPassword = (string) $response->json('data.credentials.password');
        $this->assertNotEmpty($initialPassword);

        $pharmacy = Pharmacy::where('pharmacy_name', 'Provision Test Pharmacy')->first();
        $this->assertNotNull($pharmacy);

        // كلمة المرور الأولية ليست معرّف الصيدلية (لا يمكن تخمينها من الـ ID)
        $this->assertNotSame($initialPassword, $pharmacy->pharmacy_custom_id);
        $this->assertSame($pharmacy->pharmacy_custom_id, $response->json('data.credentials.pharmacy_id'));

        $user = $pharmacy->user;
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($initialPassword, $user->password));

        // إلزام تغيير كلمة المرور عند أول تسجيل دخول
        $this->assertTrue((bool) $user->must_change_password);
    }

    /**
     * مسار الاسترجاع الوحيد للأدمن — كان يرمي 500 لأن Crypt غير مستورد.
     */
    public function test_reset_credentials_returns_new_password(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/pharmacies', [
            'pharmacy_name' => 'Reset Credentials Pharmacy',
        ])->assertCreated();

        $pharmacy = Pharmacy::where('pharmacy_name', 'Reset Credentials Pharmacy')->firstOrFail();
        $oldHash = $pharmacy->user->password;

        $response = $this->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/reset-credentials')
            ->assertOk();

        $newPassword = (string) $response->json('data.credentials.password');
        $this->assertNotEmpty($newPassword);

        $pharmacy->refresh();
        $this->assertTrue(Hash::check($newPassword, $pharmacy->user->password));
        $this->assertNotSame($oldHash, $pharmacy->user->password);
        // إلزام التغيير من جديد + إعادة تجهيز نسخة مشفّرة للرسالة
        $this->assertTrue((bool) $pharmacy->user->must_change_password);
        $this->assertNotNull($pharmacy->pending_password);
        $this->assertNull($pharmacy->delivered_at);
    }

    public function test_provisioned_pharmacy_custom_id_matches_format(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/pharmacies', [
            'pharmacy_name' => 'Format Check Pharmacy',
        ])->assertCreated();

        $pharmacy = Pharmacy::where('pharmacy_name', 'Format Check Pharmacy')->first();
        $this->assertNotNull($pharmacy);
        $this->assertMatchesRegularExpression('/^PH-[A-Z0-9]{4}$/', $pharmacy->pharmacy_custom_id);
    }

    /**
     * عند تفعيل صيدلية مسجّلة ذاتياً (delivered_at=null) → يتم تسليم بيانات
     * الدخول مرة واحدة (في `data.credentials`).
     */
    public function test_toggle_status_delivers_credentials_for_self_registered_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // صيدلية مسجّلة ذاتياً (بانتظار موافقة) — كلمة المرور اختارها صاحب الصيدلية
        $pharmacy = (new \App\Services\PharmacyRegistrationService)->createPending([
            'pharmacy_name' => 'Self Registered Pharmacy',
            'phone' => '0599111222',
            'region' => 'منطقة الاختبار',
            'password' => 'chosen-pass-123',
        ]);

        $this->assertNull($pharmacy->delivered_at);
        $this->assertFalse((bool) $pharmacy->is_active);

        $response = $this->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        // كلمة المرور المسلَّمة هي التي اختارها صاحب الصيدلية — لا كلمة عشوائية
        $this->assertSame('chosen-pass-123', $response->json('data.credentials.password'));
        $this->assertSame($pharmacy->pharmacy_custom_id, $response->json('data.credentials.pharmacy_id'));

        $pharmacy->refresh();
        $this->assertTrue((bool) $pharmacy->is_active);
        $this->assertNotNull($pharmacy->delivered_at);
        // النسخة المشفّرة صُفِّرت فور التسليم
        $this->assertNull($pharmacy->pending_password);
        // وكلمة المرور المخزّنة hash تظل مطابقة لاختيار المستخدم
        $this->assertTrue(Hash::check('chosen-pass-123', $pharmacy->user->password));

        // idempotent — تفعيل مرة ثانية لا يُعيد التسليم
        $this->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.credentials', null);
    }

    /**
     * تفعيل صيدلية منشأة من الأدمن (delivered_at موجود مسبقاً) → لا يُعاد التسليم.
     */
    public function test_toggle_status_does_not_re_deliver_for_admin_created_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/pharmacies', [
            'pharmacy_name' => 'Admin Created Pharmacy',
        ])->assertCreated();

        $pharmacy = Pharmacy::where('pharmacy_name', 'Admin Created Pharmacy')->firstOrFail();
        $pharmacy->delivered_at = now(); // الأدمن خلّاها delivered مسبقاً
        $pharmacy->save();

        // الأدمن يعطّل ثم يفعّل
        $this->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/toggle-status')->assertOk();

        $this->patchJson('/api/admin/pharmacies/'.$pharmacy->id.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.credentials', null);
    }
}
