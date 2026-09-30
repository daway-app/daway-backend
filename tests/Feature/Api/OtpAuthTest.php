<?php

namespace Tests\Feature\Api;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    public function test_send_otp_returns_code_and_stores_hashed(): void
    {
        $phone = '0599000001';

        $response = $this->postJson('/api/otp/send', ['phone' => $phone]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('otp', fn ($otp) => is_string($otp) && strlen($otp) === 6)
            ->assertJsonPath('is_registered', false);

        $plain = $response->json('otp');
        $row = OtpCode::where('phone', $phone)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($plain, $row->otp);
        $this->assertTrue(Hash::check($plain, $row->otp));
    }

    public function test_verify_otp_new_user_without_registration_data_is_rejected(): void
    {
        $phone = '0599000002';

        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        $response = $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp]);

        $response->assertStatus(422)
            ->assertJsonPath('registration_required', true);

        $this->assertDatabaseMissing('users', ['phone' => $phone]);
        $this->assertDatabaseMissing('users', ['name' => 'New User']);
    }

    public function test_send_otp_reports_is_registered_true_for_existing_user(): void
    {
        $patient = User::factory()->patient()->create();

        $this->postJson('/api/otp/send', ['phone' => $patient->phone])
            ->assertStatus(200)
            ->assertJsonPath('is_registered', true);
    }

    public function test_verify_otp_existing_patient_returns_is_new_false(): void
    {
        $patient = User::factory()->patient()->create();
        $phone = $patient->phone;

        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp])
            ->assertStatus(200)
            ->assertJsonPath('data.user.is_new', false)
            ->assertJsonPath('data.user.role', 'patient');
    }

    public function test_verify_otp_rejects_pharmacy_user(): void
    {
        $pharmacy = User::factory()->pharmacy()->create();
        $phone = $pharmacy->phone;

        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp])
            ->assertStatus(403)
            ->assertJsonPath('message', 'OTP login is not allowed for this account');
    }

    public function test_verify_otp_wrong_code_returns_400(): void
    {
        $phone = '0599000003';

        $this->postJson('/api/otp/send', ['phone' => $phone]);

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => '000000'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired OTP');
    }

    public function test_verify_otp_rate_limited_after_5_attempts(): void
    {
        $phone = '0599000004';

        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => '000000'])
                ->assertStatus(400);
        }

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp])
            ->assertStatus(429);
    }

    public function test_send_otp_rate_limited(): void
    {
        $phone = '0599000005';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/otp/send', ['phone' => $phone])->assertStatus(200);
        }

        $this->postJson('/api/otp/send', ['phone' => $phone])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // Phone validation — exactly 10 digits
    // ------------------------------------------------------------------

    public function test_send_otp_rejects_phone_with_less_than_10_digits(): void
    {
        $this->postJson('/api/otp/send', ['phone' => '059912345'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid phone number');
    }

    public function test_send_otp_rejects_phone_with_more_than_10_digits(): void
    {
        $this->postJson('/api/otp/send', ['phone' => '05991234567'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid phone number');
    }

    public function test_send_otp_rejects_phone_with_letters(): void
    {
        $this->postJson('/api/otp/send', ['phone' => '0599abcd56'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid phone number');
    }

    public function test_verify_otp_rejects_short_phone(): void
    {
        // When phone is invalid format and user doesn't exist, returns 422 registration_required
        // When phone is invalid format and user exists, returns 400 (old behavior for existing users)
        $this->postJson('/api/otp/verify', ['phone' => '12345', 'otp' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('registration_required', true);
    }
}