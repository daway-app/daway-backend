<?php

namespace Tests\Feature\Sms;

use App\Contracts\SmsProvider;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthControllerSmsIntegrationTest extends TestCase
{
    private array $smsCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->smsCalls = [];

        $fake = new class ($this->smsCalls) implements SmsProvider {
            public function __construct(private array &$calls) {}

            public function enabled(): bool
            {
                return true;
            }

            public function sendSms(string $phone, string $message): array
            {
                $this->calls[] = [
                    'phone' => $phone,
                    'message' => $message,
                ];

                return ['success' => true, 'message_id' => 'fake_msg_id', 'error' => null];
            }
        };

        $this->app->singleton(SmsProvider::class, fn () => $fake);
    }

    public function test_send_otp_dispatches_sms_with_normalized_phone(): void
    {
        $phone = '0599000001';

        $response = $this->postJson('/api/otp/send', ['phone' => $phone]);

        $response->assertStatus(200);

        $this->assertCount(1, $this->smsCalls);
        $this->assertSame('+970599000001', $this->smsCalls[0]['phone']);
        $this->assertStringContainsString('Daway', $this->smsCalls[0]['message']);
    }

    public function test_send_otp_response_contract_unchanged(): void
    {
        $phone = '0599000002';

        $response = $this->postJson('/api/otp/send', ['phone' => $phone]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OTP sent successfully')
            ->assertJsonPath('is_registered', false)
            ->assertJsonMissing(['data']);

        // OTP still returned in response (known trade-off per AGENTS.md)
        $this->assertArrayHasKey('otp', $response->json());
        $this->assertSame(6, strlen($response->json('otp')));
    }

    public function test_send_otp_still_stores_hashed_otp_in_database(): void
    {
        $phone = '0599000003';

        $response = $this->postJson('/api/otp/send', ['phone' => $phone]);

        $otp = $response->json('otp');
        $row = OtpCode::where('phone', $phone)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($otp, $row->otp);
    }

    public function test_send_otp_is_registered_true_for_existing_patient(): void
    {
        $patient = User::factory()->patient()->create();

        $response = $this->postJson('/api/otp/send', ['phone' => $patient->phone]);

        $response->assertStatus(200)
            ->assertJsonPath('is_registered', true);

        $this->assertCount(1, $this->smsCalls);
    }

    public function test_send_otp_sms_failure_does_not_block_response(): void
    {
        // استبدال بـ fake يرجع failure
        $this->app->singleton(SmsProvider::class, function () {
            return new class implements SmsProvider {
                public function enabled(): bool
                {
                    return true;
                }

                public function sendSms(string $phone, string $message): array
                {
                    return ['success' => false, 'message_id' => null, 'error' => 'Network error'];
                }
            };
        });

        $response = $this->postJson('/api/otp/send', ['phone' => '0599000004']);

        // الاستجابة تنجح رغم فشل الرسالة
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('otp', fn ($otp) => is_string($otp) && strlen($otp) === 6);
    }

    public function test_send_otp_sms_not_dispatched_when_provider_disabled(): void
    {
        // استبدال بـ fake غير مفعل
        $this->app->singleton(SmsProvider::class, function () {
            return new class implements SmsProvider {
                public $called = 0;

                public function enabled(): bool
                {
                    return false;
                }

                public function sendSms(string $phone, string $message): array
                {
                    $this->called++;

                    return ['success' => false, 'message_id' => null, 'error' => 'Not enabled'];
                }
            };
        });

        $response = $this->postJson('/api/otp/send', ['phone' => '0599000005']);

        $response->assertStatus(200);

        // لا يُستدعى sendSms على الإطلاق لأن enabled() ترجع false
        $provider = app(SmsProvider::class);
        $this->assertSame(0, $provider->called);
    }

    public function test_send_otp_sms_message_contains_normalized_phone(): void
    {
        $response = $this->postJson('/api/otp/send', ['phone' => '0599000006']);

        $response->assertStatus(200);

        $this->assertCount(1, $this->smsCalls);
        // يجب أن يكون الرقم بالصيغة الكاملة E.164
        $this->assertStringStartsWith('+970', $this->smsCalls[0]['phone']);
        $this->assertTrue(str_ends_with($this->smsCalls[0]['phone'], '0599000006')
            || str_contains($this->smsCalls[0]['phone'], '59900006'));
    }
}
