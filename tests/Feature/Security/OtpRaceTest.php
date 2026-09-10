<?php

namespace Tests\Feature\Security;

use App\Models\OtpCode;
use App\Models\User;
use Tests\TestCase;

/**
 * M-12: سباقات OTP.
 * ملاحظة: هذه محاكاة تسلسلية للسباق — التزامن الحقيقي (طلبات متوازية فعلياً)
 * يغطيه مسار الـ catch داخل الـ controller (معاملة DB + قيود فريدة).
 */
class OtpRaceTest extends TestCase
{
    public function test_send_otp_twice_same_phone_keeps_single_row(): void
    {
        $phone = '05991111001';

        $first = $this->postJson('/api/otp/send', ['phone' => $phone]);
        $first->assertStatus(200);

        $second = $this->postJson('/api/otp/send', ['phone' => $phone]);
        $second->assertStatus(200);

        // الإرسال المتكرر يعيد الكتابة (updateOrCreate) — لا صفوف مكررة
        $this->assertSame(1, OtpCode::where('phone', $phone)->count());
    }

    public function test_verify_otp_registration_then_second_verify_is_login_path(): void
    {
        $phone = '05991111002';

        // سباق التسجيل: إرسال OTP ثم verify ببيانات تسجيل كاملة → إنشاء حساب
        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');
        $this->assertNotEmpty($otp);

        $this->postJson('/api/otp/verify', [
            'phone' => $phone,
            'otp' => $otp,
            'name' => 'Race Patient',
            'birth_date' => '1995-05-05',
            'latitude' => 31.5,
            'longitude' => 34.4,
        ])->assertStatus(200)
            ->assertJsonPath('data.user.is_new', true);

        $this->assertSame(1, User::where('phone', $phone)->count());

        // verify مرة ثانية بنفس الهاتف: المستخدم موجود الآن → مسار تسجيل دخول
        $otp2 = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp2])
            ->assertStatus(200)
            ->assertJsonPath('data.user.is_new', false);

        // لم يُنشأ مستخدم مكرر رغم الطلبات المتتالية
        $this->assertSame(1, User::where('phone', $phone)->count());
    }
}
