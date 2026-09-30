<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * تدفق (تسجيل ثم OTP): POST /api/register/patient ثم POST /api/otp/verify
 * بـ phone+otp فقط — الموقع والإشعارات اختيارية لاحقاً عبر profile.
 */
class PatientDirectRegistrationTest extends TestCase
{
    public function test_register_patient_returns_otp_and_caches_data(): void
    {
        $response = $this->postJson('/api/register/patient', [
            'phone' => '0591234567',
            'name' => 'عبدالرحمن',
            'age' => 25,
            'terms_accepted' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_registered', false);

        $otp = $response->json('otp');
        $this->assertIsString($otp);
        $this->assertSame(6, strlen($otp));

        // الحساب لا يُنشأ قبل التحقق من OTP
        $this->assertDatabaseMissing('users', ['phone' => '0591234567']);

        $cached = Cache::get('patient_reg:0591234567');
        $this->assertIsArray($cached);
        $this->assertSame('عبدالرحمن', $cached['name']);
        $this->assertSame(25, $cached['age']);
    }

    public function test_verify_with_phone_and_otp_only_creates_patient_from_cache(): void
    {
        $otp = $this->postJson('/api/register/patient', [
            'phone' => '0591234568',
            'name' => 'محمد أحمد',
            'age' => 30,
            'terms_accepted' => true,
        ])->json('otp');

        $response = $this->postJson('/api/otp/verify', [
            'phone' => '0591234568',
            'otp' => $otp,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_new', true)
            ->assertJsonPath('data.user.role', 'patient')
            ->assertJsonPath('data.user.name', 'محمد أحمد');

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('phone', '0591234568')->first();
        $this->assertNotNull($user);
        $this->assertSame('patient', $user->role);
        $this->assertTrue($user->terms_accepted);
        $this->assertNull(Cache::get('patient_reg:0591234568'));

        // OTP يُستهلك بعد الاستخدام
        $this->assertDatabaseMissing('otp_codes', ['phone' => '0591234568']);
    }

    public function test_register_patient_rejects_phone_not_starting_with_05(): void
    {
        $this->postJson('/api/register/patient', [
            'phone' => '0612345678',
            'name' => 'عبدالرحمن',
            'age' => 25,
            'terms_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseMissing('users', ['phone' => '0612345678']);
    }

    public function test_register_patient_rejects_short_phone(): void
    {
        $this->postJson('/api/register/patient', [
            'phone' => '059123',
            'name' => 'عبدالرحمن',
            'age' => 25,
            'terms_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_register_patient_rejects_already_registered_phone(): void
    {
        User::factory()->patient()->create(['phone' => '0591234569']);

        $this->postJson('/api/register/patient', [
            'phone' => '0591234569',
            'name' => 'جديد',
            'age' => 22,
            'terms_accepted' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_register_patient_requires_terms_accepted(): void
    {
        $this->postJson('/api/register/patient', [
            'phone' => '0591234570',
            'name' => 'عبدالرحمن',
            'age' => 25,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['terms_accepted']);

        $this->assertDatabaseMissing('users', ['phone' => '0591234570']);
    }
}
