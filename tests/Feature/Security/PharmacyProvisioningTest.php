<?php

namespace Tests\Feature\Security;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M-4: تزويد الصيدليات من الأدمن — كلمة مرور أولية عشوائية (غير مساوية للمعرف)
 * مع flash في الجلسة وإلزام تغييرها عند أول دخول.
 *
 * ⚠️ درس مهم: كان هذا الملف يتحقق من مفاتيح الجلسة `initial_*` فقط، بينما القالب
 * الوحيد لبيانات الدخول يقرأ `delivered_*` — فمرّ العطل: كلمة المرور تُولَّد ثم
 * تُفقد ولا يراها الأدمن. لذلك نتحقق الآن من **الـHTML المرسوم** أيضاً، لا الجلسة وحدها.
 */
class PharmacyProvisioningTest extends TestCase
{
    public function test_admin_provisions_pharmacy_with_initial_password_flash(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Provision Test Pharmacy',
        ]);

        $response->assertRedirect(route('pharmacies.index'));
        // المفاتيح الموحّدة مع مسار التسجيل الذاتي (كانت initial_* = مفاتيح ميتة)
        $response->assertSessionHas('delivered_password');
        $response->assertSessionHas('delivered_pharmacy_id');

        $initialPassword = $response->getSession()->get('delivered_password');
        $this->assertNotEmpty($initialPassword);

        $pharmacy = Pharmacy::where('pharmacy_name', 'Provision Test Pharmacy')->first();
        $this->assertNotNull($pharmacy);

        // كلمة المرور الأولية ليست معرّف الصيدلية (لا يمكن تخمينها من الـ ID)
        $this->assertNotSame($initialPassword, $pharmacy->pharmacy_custom_id);

        $user = $pharmacy->user;
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($initialPassword, $user->password));

        // إلزام تغيير كلمة المرور عند أول تسجيل دخول
        $this->assertTrue((bool) $user->must_change_password);
    }

    /**
     * 🔴 الانحدار الحقيقي: صندوق بيانات الدخول يجب أن **يُرسم فعلاً** في الصفحة.
     * اختبار الجلسة وحده لا يكشف اختلاف أسماء المفاتيح — وهنا كان العطل يتخفّى.
     */
    public function test_credentials_box_is_actually_rendered_for_admin_created_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Rendered Credentials Pharmacy',
        ]);

        $password = $response->getSession()->get('delivered_password');
        $this->assertNotEmpty($password);

        // نتبع إعادة التوجيه ونفحص الـHTML — الصندوق يجب أن يظهر بكلمة المرور والمعرّف
        $page = $this->actingAs($admin)->get(route('pharmacies.index'));

        $page->assertOk();
        $page->assertSee('deliveredCredentialsBox', false);
        $page->assertSee('deliveredPassword', false);
        $page->assertSee($password, false);
        $page->assertSee($response->getSession()->get('delivered_pharmacy_id'), false);
    }

    /**
     * مسار الاسترجاع الوحيد للأدمن — كان يرمي 500 لأن Crypt غير مستورد.
     */
    public function test_reset_credentials_works_and_flashes_new_password(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Reset Credentials Pharmacy',
        ]);

        $pharmacy = Pharmacy::where('pharmacy_name', 'Reset Credentials Pharmacy')->firstOrFail();
        $oldHash = $pharmacy->user->password;

        $response = $this->actingAs($admin)
            ->patch(route('pharmacies.resetCredentials', $pharmacy->id));

        // لا 500 — كان `Class "…\Crypt" not found`
        $response->assertRedirect(route('pharmacies.index'));
        $response->assertSessionHasNoErrors();

        $newPassword = $response->getSession()->get('delivered_password');
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

        $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Format Check Pharmacy',
        ])->assertRedirect(route('pharmacies.index'));

        $pharmacy = Pharmacy::where('pharmacy_name', 'Format Check Pharmacy')->first();
        $this->assertNotNull($pharmacy);
        $this->assertMatchesRegularExpression('/^PH-[A-Z0-9]{4}$/', $pharmacy->pharmacy_custom_id);
    }

    /**
     * عند تفعيل صيدلية مسجّلة ذاتياً (delivered_at=null) → يتم تسليم بيانات الدخول مرة واحدة.
     */
    public function test_toggle_status_delivers_credentials_for_self_registered_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();

        // صيدلية مسجّلة ذاتياً (بانتظار موافقة) — كلمة المرور اختارها صاحب الصيدلية
        $pharmacy = (new \App\Services\PharmacyRegistrationService)->createPending([
            'pharmacy_name' => 'Self Registered Pharmacy',
            'phone' => '0599111222',
            'region' => 'منطقة الاختبار',
            'password' => 'chosen-pass-123',
        ]);

        $this->assertNull($pharmacy->delivered_at);
        $this->assertFalse((bool) $pharmacy->is_active);

        // الأدمن يفعّل (toggleStatus = PATCH)
        $response = $this->actingAs($admin)
            ->patch(route('pharmacies.toggleStatus', $pharmacy->id));

        $response->assertRedirect(route('pharmacies.index'));
        $response->assertSessionHas('delivered_pharmacy_id');
        // كلمة المرور المسلَّمة هي التي اختارها صاحب الصيدلية — لا كلمة عشوائية
        $response->assertSessionHas('delivered_password', 'chosen-pass-123');

        $pharmacy->refresh();
        $this->assertTrue((bool) $pharmacy->is_active);
        $this->assertNotNull($pharmacy->delivered_at);
        // النسخة المشفّرة صُفِّرت فور التسليم
        $this->assertNull($pharmacy->pending_password);
        // وكلمة المرور المخزّنة hash تظل مطابقة لاختيار المستخدم
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('chosen-pass-123', $pharmacy->user->password));

        // idempotent — تفعيل مرة ثانية لا يُعيد التسليم (toggleStatus = PATCH)
        $response2 = $this->actingAs($admin)
            ->patch(route('pharmacies.toggleStatus', $pharmacy->id));

        $response2->assertSessionMissing('delivered_pharmacy_id');
    }

    /**
     * تفعيل صيدلية منشأة من الأدمن (delivered_at موجود مسبقاً) → لا يُعاد التسليم.
     */
    public function test_toggle_status_does_not_re_deliver_for_admin_created_pharmacy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('pharmacies.store'), [
            'pharmacy_name' => 'Admin Created Pharmacy',
        ]);

        $pharmacy = Pharmacy::where('pharmacy_name', 'Admin Created Pharmacy')->firstOrFail();
        $pharmacy->delivered_at = now(); // الأدمن خلّاها delivered مسبقاً
        $pharmacy->save();

        // الأدمن يعطّل ثم يفعّل (toggleStatus = PATCH)
        $this->actingAs($admin)
            ->patch(route('pharmacies.toggleStatus', $pharmacy->id));

        $this->actingAs($admin)
            ->patch(route('pharmacies.toggleStatus', $pharmacy->id))
            ->assertSessionMissing('delivered_pharmacy_id');
    }
}
