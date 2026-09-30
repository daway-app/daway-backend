<?php

namespace Tests\Feature\Sms;

use App\Contracts\SmsProvider;
use App\Services\Sms\AndroidSmsGatewayService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AndroidSmsGatewayServiceTest extends TestCase
{
    private AndroidSmsGatewayService $service;

    private const BASE_URL = 'http://192.168.1.50:8080';

    private const USERNAME = 'gateway_user';

    private const PASSWORD = 'gateway_pass';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AndroidSmsGatewayService(
            baseUrl: self::BASE_URL,
            username: self::USERNAME,
            password: self::PASSWORD,
            timeout: 10,
            enabled: true,
            countryCode: '+970',
        );
    }

    public function test_enabled_returns_true_when_configured(): void
    {
        $this->assertTrue($this->service->enabled());
    }

    public function test_enabled_returns_false_when_disabled_flag_false(): void
    {
        $service = new AndroidSmsGatewayService(
            baseUrl: self::BASE_URL,
            username: self::USERNAME,
            password: self::PASSWORD,
            enabled: false,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_enabled_returns_false_when_base_url_missing(): void
    {
        $service = new AndroidSmsGatewayService(
            baseUrl: null,
            username: self::USERNAME,
            password: self::PASSWORD,
            enabled: true,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_enabled_returns_false_when_username_missing(): void
    {
        $service = new AndroidSmsGatewayService(
            baseUrl: self::BASE_URL,
            username: null,
            password: self::PASSWORD,
            enabled: true,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_enabled_returns_false_when_password_missing(): void
    {
        $service = new AndroidSmsGatewayService(
            baseUrl: self::BASE_URL,
            username: self::USERNAME,
            password: null,
            enabled: true,
        );

        $this->assertFalse($service->enabled());
    }

    public function test_send_sms_success_returns_message_id(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response([
                'smsId' => 'sms_12345',
                'status' => 'sent',
            ], 200),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test message');

        $this->assertTrue($result['success']);
        $this->assertSame('sms_12345', $result['message_id']);
        $this->assertNull($result['error']);
    }

    public function test_send_sms_sends_correct_payload_with_basic_auth(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['smsId' => 'sms_1'], 200),
        ]);

        $this->service->sendSms('+970599000001', 'Your code is 123456');

        Http::assertSent(function ($request) {
            return $request->url() === self::BASE_URL.'/message'
                && $request->method() === 'POST'
                && $request['textMessage']['text'] === 'Your code is 123456'
                && $request['phoneNumbers'] === ['+970599000001']
                && $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_send_sms_local_palestinian_phone_normalizes_to_e164(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['smsId' => 'sms_1'], 200),
        ]);

        $this->service->sendSms('0599000001', 'Test');

        Http::assertSent(function ($request) {
            // يجب أن يكون الرقم مطبوعاً بالصيغة E.164
            return $request['phoneNumbers'] === ['+970599000001'];
        });
    }

    public function test_send_sms_e164_phone_preserved(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['smsId' => 'sms_1'], 200),
        ]);

        $this->service->sendSms('+970599000001', 'Test');

        Http::assertSent(function ($request) {
            return $request['phoneNumbers'] === ['+970599000001'];
        });
    }

    public function test_send_sms_returns_error_on_http_failure(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response([
                'error' => 'bad_request',
                'message' => 'Invalid phone number',
            ], 400),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('400', $result['error']);
    }

    public function test_send_sms_returns_error_on_connection_timeout(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 10000ms');
        });

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('Android SMS Gateway', $result['error']);
    }

    public function test_send_sms_returns_error_when_not_enabled(): void
    {
        $service = new AndroidSmsGatewayService(
            baseUrl: null,
            username: null,
            password: null,
            enabled: false,
        );

        $result = $service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('غير مهيأ', $result['error']);
    }

    public function test_send_sms_returns_error_on_5xx_server_error(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['error' => 'internal'], 500),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('500', $result['error']);
    }

    public function test_send_sms_returns_error_on_401_unauthorized(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['error']);
    }

    public function test_send_sms_returns_error_on_429_rate_limit(): void
    {
        Http::fake([
            self::BASE_URL.'/message' => Http::response(['error' => 'rate_limit'], 429),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('429', $result['error']);
    }

    public function test_send_sms_does_not_use_smsgate_endpoint(): void
    {
        Http::fake([
            self::BASE_URL.'/3rdparty/v1/messages' => Http::response(['message_id' => 'should_not_be_called'], 200),
            self::BASE_URL.'/message' => Http::response(['smsId' => 'sms_1'], 200),
        ]);

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            // يجب أن يستخدم /message وليس /3rdparty/v1/messages
            return $request->url() === self::BASE_URL.'/message';
        });
    }

    public function test_send_sms_returns_error_on_unexpected_exception(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Something went wrong');
        });

        $result = $this->service->sendSms('+970599000001', 'Test');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }
}
