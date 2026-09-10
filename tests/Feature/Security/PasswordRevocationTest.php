<?php

namespace Tests\Feature\Security;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H-13: تغيير كلمة المرور يجب أن يلغي الجلسات/التوكنات القديمة
 * ويطلب من المستخدم تسجيل الدخول من جديد.
 */
class PasswordRevocationTest extends TestCase
{
    public function test_api_change_password_revokes_old_token_and_asks_relogin(): void
    {
        $user = User::factory()->pharmacy()->create([
            'must_change_password' => true,
            'password' => Hash::make('PH-OLDPASS1'),
        ]);
        Pharmacy::factory()->create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-OLDPASS1',
        ]);

        $oldToken = $user->createToken('old')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/pharmacy/change-password', [
                'current_password' => 'PH-OLDPASS1',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertOk();

        // الرسالة تطلب إعادة تسجيل الدخول بعد إبطال التوكنات
        $this->assertStringContainsString('تسجيل الدخول', (string) $response->json('message'));

        // التوكنات حُذفت من قاعدة البيانات فعلياً
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // محاكاة طلب إنتاج جديد: الـ RequestGuard داخل نفس عملية الاختبار
        // يخزّن المستخدم مؤقتاً عبر الـ container — forgetGuards يعيد الحل الفعلي من الـ DB
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/profile/pharmacy')
            ->assertStatus(401);
    }

    public function test_web_password_change_revokes_api_tokens(): void
    {
        $user = User::factory()->patient()->create();
        $user->createToken('old');

        $this->assertSame(1, $user->tokens()->count());

        $response = $this->actingAs($user)->from('/profile')->post('/profile/password', [
            '_method' => 'PUT',
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect();

        // H-13: أي توكنات API صادرة سابقاً للحساب تُبطَل بعد تغيير كلمة المرور من الويب
        $user->refresh();
        $this->assertSame(0, $user->tokens()->count());
    }
}
