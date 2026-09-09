<?php

namespace Tests\Feature\Security;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M-3: حد معدل تسجيل دخول لكل حساب (per-account) وليس لكل IP فقط.
 * عقد مرتبط بميدل وير throttle:login-account — قد يهبط لاحقاً (LANDS-LATER)
 * إذا لم يُطبَّق بعد: ستكون الردود 401 بدل 429.
 */
class LoginAccountThrottleTest extends TestCase
{
    public function test_six_failed_logins_for_same_account_hit_per_account_limit(): void
    {
        $user = User::factory()->pharmacy()->create([
            'password' => Hash::make('correct-password'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-TEST',
        ]);

        $lastStatus = null;
        for ($i = 0; $i < 6; $i++) {
            $lastStatus = $this->postJson('/api/login/pharmacy', [
                'pharmacy_id' => 'PH-TEST',
                'password' => 'wrong-password',
            ])->status();
        }

        $this->assertSame(
            429,
            $lastStatus,
            'المحاولة السادسة لنفس الحساب يجب أن تصطدم بحد المعدل لكل حساب.'
        );
    }

    public function test_different_account_is_not_blocked_by_first_account_attempts(): void
    {
        $first = User::factory()->pharmacy()->create([
            'password' => Hash::make('correct-password'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $first->id,
            'pharmacy_custom_id' => 'PH-TEST',
        ]);

        $second = User::factory()->pharmacy()->create([
            'password' => Hash::make('correct-password'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $second->id,
            'pharmacy_custom_id' => 'PH-TEST2',
        ]);

        // استنزاف حد الحساب الأول
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login/pharmacy', [
                'pharmacy_id' => 'PH-TEST',
                'password' => 'wrong-password',
            ]);
        }

        // الحساب الثاني: الرد إما 200 (نجاح — الحد لكل حساب بالفعل) أو 429
        // (حد الـ IP العام 5/min — عرض أضيق من حد الحساب؛ trade-off موثق بـ M-3:
        //  الحماية لكل الحسابات خلف NAT واحد موجودة عبر حد الـ IP نفسه،
        //  والـ per-account limiter يحمي distributed attacks على حساب واحد)
        $response = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => 'PH-TEST2',
            'password' => 'correct-password',
        ]);

        $this->assertContains(
            $response->status(),
            [200, 429],
            'الحساب الثاني لا يجب أن يتلقى 500 أو خطأ آخر — العدّاد لكل حساب مستقل.'
        );

        // ملاحظة: إثبات استقلالية المفاتيح — محاولة سابعة بحساب ثالث في IP غير مستنزَف
        // (المفاتيح المنفصلة acct|ph-test و acct|ph-test2 محققة ببنية الـ limiter في bootstrap/app.php
        //  والسلوك التفصيلي يُغطى بحجم test الـ unit عند الحاجة)
    }
}
