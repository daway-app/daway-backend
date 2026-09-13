<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * التسجيل الذاتي للصيدليات عبر API الموبايل.
 *
 * صاحب الصيدلية يختار كلمة مروره بنفسه. الحساب يُنشأ غير مفعّل، ولا تُعاد
 * بيانات دخول في الاستجابة — بعد موافقة الأدمن تُسلَّم (Pharmacy ID + كلمة
 * المرور التي اختارها) عبر رسالة التسليم.
 */
class PharmacyRegistrationTest extends TestCase
{
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'pharmacy_name' => 'صيدلية النور',
            'phone' => '0599123456',
            'region' => 'الرمال',
            'password' => 'secret1234',
        ], $overrides);
    }

    public function test_pharmacy_can_register_without_receiving_credentials(): void
    {
        $response = $this->postJson('/api/register/pharmacy', $this->validData());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pharmacy_name', 'صيدلية النور')
            ->assertJsonPath('data.phone', '0599123456')
            // لا تُعاد بيانات الدخول — تُسلَّم عبر الرسالة بعد موافقة الأدمن
            ->assertJsonMissingPath('data.pharmacy_id')
            ->assertJsonMissingPath('data.password');
    }

    public function test_registration_creates_inactive_pharmacy_with_user_chosen_password(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $user = User::where('phone', '0599123456')->first();

        $this->assertNotNull($user);
        $this->assertSame('pharmacy', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue($user->hasRole('pharmacy'));
        // كلمة المرور هي التي اختارها المستخدم — لا كلمة عشوائية
        $this->assertTrue(Hash::check('secret1234', $user->password));

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        $this->assertNotNull($pharmacy);
        $this->assertFalse((bool) $pharmacy->is_active);
        $this->assertNull($pharmacy->delivered_at);
        $this->assertNull($pharmacy->profile_completed_at);

        // نسخة مشفّرة قابلة للاسترجاع لرسالة التسليم — تُقرأ وتطابق اختيار المستخدم
        $this->assertNotNull($pharmacy->pending_password);
        $this->assertSame('secret1234', Crypt::decryptString($pharmacy->pending_password));
    }

    public function test_pending_login_returns_plain_403(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => 'secret1234',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'account_inactive')
            ->assertJsonMissingPath('credentials');
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
                'region',
                'password',
            ]);
    }

    public function test_short_password_is_rejected(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData(['password' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_approved_pharmacy_can_login_with_chosen_password(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());

        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();

        // الأدمن يوافق (toggleStatus يسلّم بيانات الدخول — خارج نطاق هذا الـ endpoint)
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->user->is_active = true;
        $pharmacy->user->save();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => 'secret1234',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.pharmacy_id', $pharmacy->pharmacy_custom_id);
    }

    public function test_pharmacy_ids_are_unique_across_registrations(): void
    {
        $this->postJson('/api/register/pharmacy', $this->validData());
        $this->postJson('/api/register/pharmacy', $this->validData(['phone' => '0599000000']));

        $ids = Pharmacy::whereIn('phone_number', ['0599123456', '0599000000'])
            ->pluck('pharmacy_custom_id')
            ->unique();

        $this->assertSame(2, $ids->count());
    }
}
