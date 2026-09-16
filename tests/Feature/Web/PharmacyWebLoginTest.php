<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * دخول الصيدلية عبر الويب + إلزام تغيير كلمة المرور المؤقتة.
 *
 * يغطّي ثغرتين كانتا مفتوحتين:
 *  1. must_change_password كان يُرفع عند إنشاء الصيدلية لكن مسار الويب يتجاهله.
 *  2. فحص is_active كان يسبق فحص كلمة المرور ⇒ تسريب وجود الحساب + تضليل المُشخِّص.
 */
class PharmacyWebLoginTest extends TestCase
{
    private function makePharmacy(array $userAttrs = [], array $pharmacyAttrs = []): array
    {
        $user = User::factory()->pharmacy()->create(array_merge([
            'password' => Hash::make('Temp-Pass-123'),
        ], $userAttrs));

        $pharmacy = Pharmacy::factory()->create(array_merge([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-TEST01',
            'profile_completed_at' => now(),
        ], $pharmacyAttrs));

        return [$user, $pharmacy];
    }

    public function test_pharmacy_with_temporary_password_is_sent_to_change_it(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'Temp-Pass-123',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect(route('pharmacy.password.change.show'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_pharmacy_without_temporary_password_goes_to_dashboard(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => false]);

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'Temp-Pass-123',
            'account_type' => 'pharmacy',
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 🔴 الثغرة الأساسية: كلمة مرور خاطئة على حساب **غير مفعّل** يجب أن تعيد
     * الرسالة العامة — لا "الحساب غير مفعّل". وإلا صار فحص is_active وسيلة
     * لمعرفة وجود الحساب بلا كلمة مرور.
     */
    public function test_wrong_password_on_inactive_account_does_not_leak_status(): void
    {
        $this->makePharmacy(['is_active' => false], ['is_active' => false]);

        $this->get(route('login.show'));

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'definitely-wrong',
            'account_type' => 'pharmacy',
        ]);

        $response->assertSessionHasErrors(['identity' => 'بيانات الاعتماد غير صحيحة.']);
        $this->assertGuest();
    }

    /**
     * وبكلمة المرور الصحيحة، تُعرض رسالة التفعيل (المستخدم أثبت ملكيته للحساب).
     */
    public function test_correct_password_on_inactive_account_shows_activation_message(): void
    {
        $this->makePharmacy(['is_active' => false], ['is_active' => false]);

        $this->get(route('login.show'));

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'Temp-Pass-123',
            'account_type' => 'pharmacy',
        ]);

        $response->assertSessionHasErrors('identity');
        $response->assertSessionHasErrors([
            'identity' => 'الحساب غير مفعّل. إن كان قد سجّل حديثاً فسيُفعَّل بعد موافقة الإدارة.',
        ]);
        $this->assertGuest();
    }

    public function test_unknown_pharmacy_id_returns_generic_error(): void
    {
        $this->get(route('login.show'));

        $response = $this->post(route('login'), [
            'identity' => 'PH-NOSUCH',
            'password' => 'whatever',
            'account_type' => 'pharmacy',
        ]);

        $response->assertSessionHasErrors(['identity' => 'بيانات الاعتماد غير صحيحة.']);
        $this->assertGuest();
    }

    /**
     * الوسيط: أي صفحة صيدلية أخرى تُعيد التوجيه لصفحة تغيير كلمة المرور.
     */
    public function test_middleware_blocks_dashboard_until_password_changed(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $response = $this->actingAs($user)->get(route('pharmacy.dashboard.index'));

        $response->assertRedirect(route('pharmacy.password.change.show'));
    }

    public function test_password_change_page_is_reachable_while_flagged(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $this->actingAs($user)->get(route('pharmacy.password.change.show'))
            ->assertOk();
    }

    public function test_password_change_clears_the_flag_and_unlocks_dashboard(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $response = $this->actingAs($user)->post(route('pharmacy.password.change'), [
            'current_password' => 'Temp-Pass-123',
            'password' => 'Brand-New-Pass1',
            'password_confirmation' => 'Brand-New-Pass1',
        ]);

        $response->assertRedirect(route('pharmacy.dashboard.index'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass1', $user->password));

        // وبعد التغيير، اللوحة متاحة فعلاً (لا إعادة توجيه لصفحة كلمة المرور).
        // ملاحظة: المصنع يضبط profile_completed_at، فاللوحة تُحمَّل مباشرة (200).
        $this->actingAs($user)->get(route('pharmacy.dashboard.index'))
            ->assertOk();
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $response = $this->actingAs($user)->post(route('pharmacy.password.change'), [
            'current_password' => 'wrong-current',
            'password' => 'Brand-New-Pass1',
            'password_confirmation' => 'Brand-New-Pass1',
        ]);

        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue($user->must_change_password, 'العلم يجب أن يبقى true بعد محاولة فاشلة.');
    }

    public function test_password_change_rejects_reusing_the_temporary_password(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        $response = $this->actingAs($user)->post(route('pharmacy.password.change'), [
            'current_password' => 'Temp-Pass-123',
            'password' => 'Temp-Pass-123',
            'password_confirmation' => 'Temp-Pass-123',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_change_rejects_weak_or_mismatched_password(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => true]);

        // قصيرة جداً + غير متطابقة
        $this->actingAs($user)->post(route('pharmacy.password.change'), [
            'current_password' => 'Temp-Pass-123',
            'password' => 'abc',
            'password_confirmation' => 'xyz',
        ])->assertSessionHasErrors('password');
    }

    /**
     * الصفحة غير متاحة لمن لا يحمل العلم (لا حاجة لها).
     */
    public function test_password_change_page_redirects_when_flag_is_clear(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => false]);

        $this->actingAs($user)->get(route('pharmacy.password.change.show'))
            ->assertRedirect(route('pharmacy.dashboard.index'));
    }

    /**
     * 🔴 حارس الدور: حساب مرتبط بصيدلية تغيّر دوره إلى patient/admin من لوحة الأدمن.
     *
     * قبل الحارس: الدخول "ينجح" (كلمة المرور صحيحة + الحساب مفعّل) ثم يردّه
     * EnsureRole إلى صفحة الدخول برسالة عامة ⇒ يبدو للمستخدم كأن الدخول توقف
     * فجأة بعد أن كان يعمل، بلا أي تفسير.
     */
    public function test_pharmacy_account_whose_role_changed_fails_with_clear_message(): void
    {
        [$user, $pharmacy] = $this->makePharmacy(['must_change_password' => false]);

        // محاكاة ما كان يفعله Admin\UserController::update() قبل الإصلاح
        $user->forceFill(['role' => 'patient'])->save();

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'Temp-Pass-123',
            'account_type' => 'pharmacy',
        ]);

        $response->assertSessionHasErrors('identity');
        $this->assertGuest('web');

        $message = session('errors')->first('identity');
        $this->assertStringContainsString('لم يعد حساب صيدلية', $message);
    }

    /**
     * لا تسريب: كلمة مرور خاطئة على حساب تغيّر دوره تُعطي الرسالة العامة،
     * لا رسالة الدور — وإلا صار فحص الدور وسيلة لمعرفة وجود الحساب.
     */
    public function test_wrong_password_on_role_changed_account_still_generic(): void
    {
        [$user] = $this->makePharmacy(['must_change_password' => false]);
        $user->forceFill(['role' => 'patient'])->save();

        $response = $this->post(route('login'), [
            'identity' => 'PH-TEST01',
            'password' => 'definitely-wrong',
            'account_type' => 'pharmacy',
        ]);

        $response->assertSessionHasErrors('identity');
        $this->assertGuest('web');

        $message = session('errors')->first('identity');
        $this->assertStringContainsString('بيانات الاعتماد غير صحيحة', $message);
        $this->assertStringNotContainsString('لم يعد حساب صيدلية', $message);
    }
}
