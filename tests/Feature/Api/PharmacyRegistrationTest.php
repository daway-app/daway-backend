<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * التسجيل الذاتي للصيدليات عبر API الموبايل.
 *
 * الحساب يُنشأ غير مفعّل مع كلمة مرور عشوائية.
 * لا يُعاد Pharmacy ID — يُسلّم فقط بعد موافقة الأدمن (عبر login لحساب pending).
 */
class PharmacyRegistrationTest extends TestCase
{
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'pharmacy_name' => 'صيدلية النور',
            'phone' => '0599123456',
            'address' => 'شارع عمر المختار',
            'region' => 'الرمال',
        ], $overrides);
    }

    public function test_pharmacy_can_register_without_receiving_id(): void
    {
        $response = $this->postJson('/api/register/pharmacy', $this->validData());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pharmacy_name', 'صيدلية النور')
            ->assertJsonPath('data.phone', '0599123456')
            ->assertJsonMissingPath('data.pharmacy_id'); // لا يظهر ID
    }

    public function test_registration_creates_inactive_pharmacy_with_random_password(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $user = User::where('phone', '0599123456')->first();

        $this->assertNotNull($user);
        $this->assertSame('pharmacy', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue($user->hasRole('pharmacy'));

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        $this->assertNotNull($pharmacy);
        $this->assertFalse((bool) $pharmacy->is_active);
        $this->assertNull($pharmacy->delivered_at);
        $this->assertNull($pharmacy->profile_completed_at);

        // كلمة المرور عشوائية 32 حرف — نتحقق إنها مش "password"
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_pending_login_returns_credentials_in_json(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();
        $plainPassword = $this->getPlainPassword($pharmacy);

        $response = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $plainPassword,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive')
            ->assertJsonPath('message', 'Account is inactive')
            ->assertJsonStructure(['credentials' => ['pharmacy_id', 'password']])
            ->assertJsonPath('credentials.pharmacy_id', $pharmacy->pharmacy_custom_id);

        // بعد التسليم، delivered_at يُحدّث
        $pharmacy->refresh();
        $this->assertNotNull($pharmacy->delivered_at);
    }

    public function test_second_pending_login_does_not_re_deliver(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();
        $pwd = $this->getPlainPassword($pharmacy);

        // أول دخول → يُسلّم (الـ login endpoint بيستدعي deliver() لأن delivered_at = null)
        $r1 = $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $pwd,
        ]);
        $r1->assertStatus(403)
            ->assertJsonPath('credentials.pharmacy_id', $pharmacy->pharmacy_custom_id);

        // نستخدم كلمة المرور الجديدة اللي رجّعها الـ login
        $newPwd = $r1->json('credentials.password');

        // ثاني دخول → لا يُعيد التسليم (idempotent)
        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $newPwd,
        ])->assertStatus(403)
            ->assertJsonMissingPath('credentials');
    }

    public function test_pending_login_with_wrong_password_returns_plain_403(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid login credentials');
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        User::factory()->patient()->create(['phone' => '0599123456']);

        $this->postJson('/api/register/pharmacy', $this->validData())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseMissing('pharmacies', ['pharmacy_name' => 'صيدلية النور']);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->postJson('/api/register/pharmacy', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'pharmacy_name',
                'phone',
                'address',
                'region',
            ]);
    }

    public function test_approved_pharmacy_can_login_normally(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();
        $pwd = $this->getPlainPassword($pharmacy);

        // الأدمن يوافق (بدون ما الصيدلية تحاول تدخل — يعني delivered_at لسا null)
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->user->is_active = true;
        $pharmacy->user->save();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $pwd,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.pharmacy_id', $pharmacy->pharmacy_custom_id);
    }

    /**
     * Helper: نسترجع كلمة المرور العشوائية بدون تعديل delivered_at.
     *
     * في بيئة الاختبار، نولّد كلمة مرور جديدة ونحدّثها على الـ user مباشرةً
     * (لأننا ما نقدر نرجّع الـ plaintext من otp_codes.otp اللي مخزّن hashed).
     */
    private function getPlainPassword(Pharmacy $pharmacy): string
    {
        $plain = 'test-plain-'.uniqid();
        $pharmacy->user->password = Hash::make($plain);
        $pharmacy->user->save();

        return $plain;
    }
}
