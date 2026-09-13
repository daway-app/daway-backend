<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * التسجيل الذاتي للصيدليات عبر API الموبايل.
 *
 * الحساب يُنشأ غير مفعّل، وبانات الدخول (Pharmacy ID + كلمة المرور) تُسلَّم
 * فوراً في استجابة التسجيل — نفس نمط OTP تبع المريض (بدل SMS حالياً).
 * الحساب يبقى بلا دخول حتى يوافق الأدمن.
 */
class PharmacyRegistrationTest extends TestCase
{
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'pharmacy_name' => 'صيدلية النور',
            'phone' => '0599123456',
            'region' => 'الرمال',
        ], $overrides);
    }

    public function test_pharmacy_can_register_and_receives_credentials_in_json(): void
    {
        $response = $this->postJson('/api/register/pharmacy', $this->validData());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.pharmacy_name', 'صيدلية النور')
            ->assertJsonPath('data.phone', '0599123456');

        $this->assertMatchesRegularExpression('/^PH-[A-Z0-9]{4}$/', $response->json('data.pharmacy_id'));
        // كلمة مرور 8 أحرف قابلة للكتابة — ليست كلمة المرور الأولية العشوائية الطويلة
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $response->json('data.password'));

        // التسليم فوراً → delivered_at مضبوط (idempotent لاحقاً)
        $pharmacy = Pharmacy::where('phone_number', '0599123456')->firstOrFail();
        $this->assertNotNull($pharmacy->delivered_at);
    }

    public function test_registration_creates_inactive_pharmacy_with_delivered_password(): void
    {
        $plainPassword = $this->postJson('/api/register/pharmacy', $this->validData())
            ->json('data.password');

        $user = User::where('phone', '0599123456')->first();

        $this->assertNotNull($user);
        $this->assertSame('pharmacy', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue($user->hasRole('pharmacy'));
        $this->assertTrue(Hash::check($plainPassword, $user->password), 'كلمة المرور المسلَّمة يجب أن تطابق المخزّنة');

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        $this->assertNotNull($pharmacy);
        $this->assertFalse((bool) $pharmacy->is_active);
        $this->assertNull($pharmacy->profile_completed_at);
    }

    public function test_pending_login_returns_plain_403_without_credentials(): void
    {
        $data = $this->postJson('/api/register/pharmacy', $this->validData())->json('data');

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $data['pharmacy_id'],
            'password' => $data['password'],
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
            ]);
    }

    public function test_approved_pharmacy_can_login_with_delivered_credentials(): void
    {
        $data = $this->postJson('/api/register/pharmacy', $this->validData())->json('data');

        // الأدمن يوافق — بيانات الدخول سُلّمت عند التسجيل فلا يُعاد تسليمها
        $pharmacy = Pharmacy::where('pharmacy_custom_id', $data['pharmacy_id'])->firstOrFail();
        $pharmacy->is_active = true;
        $pharmacy->save();
        $pharmacy->user->is_active = true;
        $pharmacy->user->save();

        $this->postJson('/api/login/pharmacy', [
            'pharmacy_id' => $data['pharmacy_id'],
            'password' => $data['password'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.pharmacy_id', $data['pharmacy_id']);
    }

    public function test_pharmacy_ids_are_unique_across_registrations(): void
    {
        $first = $this->postJson('/api/register/pharmacy', $this->validData())->json('data.pharmacy_id');

        $second = $this->postJson('/api/register/pharmacy', $this->validData([
            'phone' => '0599000000',
        ]))->json('data.pharmacy_id');

        $this->assertNotSame($first, $second);
    }
}
