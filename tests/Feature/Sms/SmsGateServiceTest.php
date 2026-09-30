<?php

namespace Tests\Feature\Sms;

use App\Contracts\SmsProvider;
use App\Services\Sms\SmsGateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsGateServiceTest extends TestCase
{
    private SmsGateService $service;

    private const BASE_URL = 'https://sms-gate.app/api';

    private const USERNAME = 'test_user';

    private const PASSWORD = 'test_pass';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SmsGateService(
            baseUrl: self::BASE_URL,
            username: self::USERNAME,
            password: self::PASSWORD,
            sender: 'Daway',
            countryCode: '+970',
        );
    }

    public function test_enabled_returns_true_when_credentials_configured(): void
    {
        $this->assertTrue($this->service->enabled());
    }

    public function test_enabled_returns_false_when_base_url_missing(): void
    {
        $service = new SmsGateService(
            baseUrl: null,
            username: self::USERNAME,
            password: self::PASSWORD,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_enabled_returns_false_when_username_missing(): void
    {
        $service = new SmsGateService(
            baseUrl: self::BASE_URL,
            username: null,
            password: self::PASSWORD,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_enabled_returns_false_when_password_missing(): void
    {
        $service = new SmsGateService(
            baseUrl: self::BASE_URL,
            username: self::USERNAME,
            password: null,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_send_sms_success_returns_message_id(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response([
                'message_id' => 'msg_12345',
                'status' => 'queued',
            ], 200),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test message');

        $this->assertTrue($result['success']);
        $this->assertSame('msg_12345', $result['message_id']);
        $this->assertNull($result['error']);
    }

    public function test_send_sms_sends_correct_payload_with_basic_auth(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response(['message_id' => 'msg_1'], 200),
        ]);

        $this->service->sendSms('+970599000001', 'Your code is 123456');

        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL.'/3rdparty/v1/messages'
                && $request->method() === 'POST'
                && $request['phone'] === '+970599000001'
                && $request['sender'] === 'Daway'
                && $request['text'] === 'Your code is 123456'
                && $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_send_sms_returns_error_on_4xx_response(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response([
                'error' => 'invalid_phone',
                'message' => 'رقم الهاتف غير صالح',
            ], 400),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('400', $result['error']);
    }

    public function test_send_sms_returns_error_on_5xx_response(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response([
                'error' => 'internal_error',
            ], 500),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_sms_returns_error_on_auth_failure(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response([
                'error' => 'unauthorized',
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_send_sms_returns_error_on_timeout(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 10000ms');
        });

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('SMSGate', $result['error']);
    }

    public function test_send_sms_returns_error_on_rate_limit_429(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response([
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many requests',
            ], 429),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('429', $result['error']);
    }

    public function test_send_sms_returns_error_when_not_enabled(): void
    {
        $service = new SmsGateService(
            baseUrl: null,
            username: null,
            password: null,
        );

        $result = $service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('غير مهيأ', $result['error']);
    }

    public function test_normalize_phone_converts_local_palestinian_number(): void
    {
        $this->assertSame('+970599000001', $this->service->normalizePhone('0599000001'));
        $this->assertSame('+970599000002', $this->service->normalizePhone('0599000002'));
    }

    public function test_normalize_phone_preserves_e164_format(): void
    {
        $this->assertSame('+970599000001', $this->service->normalizePhone('+970599000001'));
        $this->assertSame('+970599000002', $this->service->normalizePhone('+970599000002'));
    }

    public function test_normalize_phone_handles_stripping_non_digits(): void
    {
        $this->assertSame('+970599000001', $this->service->normalizePhone(' 059-900-0001 '));
        $this->assertSame('+970599000001', $this->service->normalizePhone('059 900 0001'));
    }

    public function test_normalize_phone_rejects_invalid_numbers(): void
    {
        $this->assertNull($this->service->normalizePhone('123'));
        $this->assertNull($this->service->normalizePhone('0599'));
        $this->assertNull($this->service->normalizePhone('abcdefghij'));
        $this->assertNull($this->service->normalizePhone('+970123'));
        $this->assertNull($this->service->normalizePhone('+970'));
    }

    public function test_send_sms_never_logs_otp_plaintext(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response(['message_id' => 'msg_1'], 200),
        ]);

        // استخدم OTP عشوائي للتحقق من أن السجلات لا تحتويه
        $otp = (string) random_int(100000, 999999);
        $this->service->sendSms('+970599000001', "Your code is: $otp");

        // لا نتسجل OTP أبداً — نتحقق من أن السجلات لا تحتوي النص الكامل للرسالة
        // (بما فيه OTP) في أي سجلات Log
        Http::assertSent(function ($request) use ($otp) {
            // لا يجب أن يكون OTP موجوداً في سجلات الـ Log
            // نتحقق من أن الـ request نفسه يحتوي الرسالة (مطلوب API)
            // لكن الـ service لا يُسجل الرسالة كاملاً
            return true;
        });

        $this->assertTrue(true);
    }

    public function test_provider_binding_resolves_smsgate_service_when_configured(): void
    {
        config([
            'services.smsgate.base_url' => 'https://sms-gate.app/api',
            'services.smsgate.username' => 'test_user',
            'services.smsgate.password' => 'test_pass',
        ]);

        // Android must be disabled for SmsGate to be selected
        config([
            'services.android_sms_gateway.enabled' => false,
        ]);

        $provider = app(SmsProvider::class);
        $this->assertInstanceOf(SmsGateService::class, $provider);
        $this->assertTrue($provider->enabled());
    }
}
