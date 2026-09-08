<?php

namespace Tests\Feature\Api;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $phone = '0591234567';

    private function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'phone' => $this->phone,
            'name' => 'عبدالرحمن',
            'birth_date' => '2005-08-15',
            'latitude' => 31.9522,
            'longitude' => 35.2332,
            'notifications_enabled' => true,
        ], $overrides);
    }

    private function sendOtpAndGetCode(?string $phone = null): string
    {
        $response = $this->postJson('/api/otp/send', ['phone' => $phone ?? $this->phone]);

        $response->assertStatus(200)->assertJsonPath('is_registered', false);

        return $response->json('otp');
    }

    // ------------------------------------------------------------------
    // Existing user
    // ------------------------------------------------------------------

    public function test_existing_user_logs_in_without_registration_data(): void
    {
        $patient = User::factory()->patient()->create([
            'phone' => $this->phone,
            'name' => 'المستخدم الأصلي',
        ]);

        $before = User::count();

        $otp = $this->postJson('/api/otp/send', ['phone' => $this->phone])->json('otp');

        $this->postJson('/api/otp/verify', [
            'phone' => $this->phone,
            'otp' => $otp,
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_new', false)
            ->assertJsonPath('data.user.name', 'المستخدم الأصلي')
            ->assertJsonPath('data.user.role', 'patient');

        $this->assertSame($before, User::count(), 'لم يجب إنشاء أي User جديد');
        $this->assertDatabaseHas('users', [
            'id' => $patient->id,
            'name' => 'المستخدم الأصلي',
        ]);
    }

    // ------------------------------------------------------------------
    // New user — validation failures
    // ------------------------------------------------------------------

    public function test_new_user_without_name_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $data = $this->validRegistrationData();
        unset($data['name']);

        $this->postJson('/api/otp/verify', array_merge($data, ['otp' => $otp]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_without_birth_date_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $data = $this->validRegistrationData();
        unset($data['birth_date']);

        $this->postJson('/api/otp/verify', array_merge($data, ['otp' => $otp]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_without_latitude_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $data = $this->validRegistrationData();
        unset($data['latitude']);

        $this->postJson('/api/otp/verify', array_merge($data, ['otp' => $otp]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_without_longitude_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $data = $this->validRegistrationData();
        unset($data['longitude']);

        $this->postJson('/api/otp/verify', array_merge($data, ['otp' => $otp]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['longitude']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_latitude_out_of_range_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
            'latitude' => 91,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_longitude_out_of_range_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
            'longitude' => -181,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['longitude']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_new_user_future_birth_date_fails(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
            'birth_date' => now()->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    // ------------------------------------------------------------------
    // New user — successful registration
    // ------------------------------------------------------------------

    public function test_new_user_registers_successfully_with_all_data(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $response = $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_new', true)
            ->assertJsonPath('data.user.role', 'patient')
            ->assertJsonPath('data.user.name', 'عبدالرحمن');

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('phone', $this->phone)->first();

        $this->assertNotNull($user);
        $this->assertSame('عبدالرحمن', $user->name);
        $this->assertSame('2005-08-15', $user->birth_date->toDateString());
        $this->assertSame(31.9522, (float) $user->latitude);
        $this->assertSame(35.2332, (float) $user->longitude);
        $this->assertTrue($user->notifications_enabled);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('patient', $user->role);

        // لا يظهر الاسم المؤقت القديم بأي حال
        $this->assertDatabaseMissing('users', ['name' => 'New User']);

        // استهلاك OTP عند النجاح
        $this->assertDatabaseMissing('otp_codes', ['phone' => $this->phone]);
    }

    public function test_new_user_registration_defaults_notifications_to_false_when_null(): void
    {
        $otp = $this->sendOtpAndGetCode();

        $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
            'notifications_enabled' => null,
        ]))->assertStatus(200);

        $user = User::where('phone', $this->phone)->first();
        $this->assertFalse((bool) $user->notifications_enabled);
    }

    public function test_registration_fields_are_ignored_for_existing_user(): void
    {
        User::factory()->patient()->create([
            'phone' => $this->phone,
            'name' => 'الأصلي',
            'latitude' => null,
            'longitude' => null,
        ]);

        $otp = $this->postJson('/api/otp/send', ['phone' => $this->phone])->json('otp');

        $this->postJson('/api/otp/verify', $this->validRegistrationData([
            'otp' => $otp,
            'name' => 'محاولة تعديل',
        ]))->assertStatus(200)
            ->assertJsonPath('data.user.is_new', false);

        $this->assertDatabaseHas('users', [
            'phone' => $this->phone,
            'name' => 'الأصلي',
        ]);
    }

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------

    public function test_cannot_create_account_without_valid_otp(): void
    {
        $this->postJson('/api/otp/send', ['phone' => $this->phone]);

        // بيانات كاملة لكن بدون OTP إطلاقاً → validation failure بعقد التسجيل الجديد
        $this->postJson('/api/otp/verify', $this->validRegistrationData())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp'])
            ->assertJsonPath('registration_required', true);

        // بيانات كاملة مع OTP خاطئ
        $this->postJson('/api/otp/verify', $this->validRegistrationData(['otp' => '000000']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired OTP');

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }

    public function test_cannot_register_by_tampering_with_otp_record(): void
    {
        $otp = $this->sendOtpAndGetCode();

        // حتى لو عُدّل سجل OTP بقيمة معروفة، الهاش يبقى هو الحكم
        OtpCode::where('phone', $this->phone)->update(['otp' => '111111']);

        $this->postJson('/api/otp/verify', $this->validRegistrationData(['otp' => '111111']))
            ->assertStatus(400);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);

        // والكود الأصلي لا يعمل بعد التلاعب
        $this->postJson('/api/otp/verify', $this->validRegistrationData(['otp' => $otp]))
            ->assertStatus(400);

        $this->assertDatabaseMissing('users', ['phone' => $this->phone]);
    }
}
