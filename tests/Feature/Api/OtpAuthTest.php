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
        $phone = '05990000001';

        $response = $this->postJson('/api/otp/send', ['phone' => $phone]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('otp', fn ($otp) => is_string($otp) && strlen($otp) === 6);

        $plain = $response->json('otp');
        $row = OtpCode::where('phone', $phone)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($plain, $row->otp);
        $this->assertTrue(Hash::check($plain, $row->otp));
    }

    public function test_verify_otp_creates_new_patient_with_is_new_true(): void
    {
        $phone = '05990000002';

        $otp = $this->postJson('/api/otp/send', ['phone' => $phone])->json('otp');

        $response = $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => $otp]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_new', true)
            ->assertJsonPath('data.user.role', 'patient');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', ['phone' => $phone, 'role' => 'patient']);
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
        $phone = '05990000003';

        $this->postJson('/api/otp/send', ['phone' => $phone]);

        $this->postJson('/api/otp/verify', ['phone' => $phone, 'otp' => '000000'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid or expired OTP');
    }

    public function test_verify_otp_rate_limited_after_5_attempts(): void
    {
        $phone = '05990000004';

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
        $phone = '05990000005';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/otp/send', ['phone' => $phone])->assertStatus(200);
        }

        $this->postJson('/api/otp/send', ['phone' => $phone])->assertStatus(429);
    }
}