<?php

namespace Tests\Feature\Sms;

use App\Contracts\SmsProvider;
use App\Services\Sms\AndroidSmsGatewayService;
use App\Services\Sms\SmsGateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsProviderSelectionTest extends TestCase
{
    public function test_provider_selection_prefers_android_when_enabled(): void
    {
        config([
            'services.android_sms_gateway.enabled' => true,
            'services.android_sms_gateway.base_url' => 'http://192.168.1.50:8080',
            'services.android_sms_gateway.username' => 'android_user',
            'services.android_sms_gateway.password' => 'android_pass',
            'services.smsgate.base_url' => 'https://sms-gate.app/api',
            'services.smsgate.username' => 'smsgate_user',
            'services.smsgate.password' => 'smsgate_pass',
        ]);

        $provider = app(SmsProvider::class);

        $this->assertInstanceOf(AndroidSmsGatewayService::class, $provider);
        $this->assertTrue($provider->enabled());
    }

    public function test_provider_selection_falls_back_to_smsgate_when_android_disabled(): void
    {
        config([
            'services.android_sms_gateway.enabled' => false,
            'services.smsgate.base_url' => 'https://sms-gate.app/api',
            'services.smsgate.username' => 'smsgate_user',
            'services.smsgate.password' => 'smsgate_pass',
        ]);

        $provider = app(SmsProvider::class);

        $this->assertInstanceOf(SmsGateService::class, $provider);
        $this->assertTrue($provider->enabled());
    }

    public function test_provider_selection_falls_back_to_noop_when_all_unconfigured(): void
    {
        config([
            'services.android_sms_gateway.enabled' => false,
            'services.smsgate.base_url' => null,
            'services.smsgate.username' => null,
            'services.smsgate.password' => null,
        ]);

        $provider = app(SmsProvider::class);

        $this->assertFalse($provider->enabled());
    }

    public function test_provider_selection_uses_android_over_smsgate_when_both_configured(): void
    {
        config([
            'services.android_sms_gateway.enabled' => true,
            'services.android_sms_gateway.base_url' => 'http://192.168.1.50:8080',
            'services.android_sms_gateway.username' => 'android_user',
            'services.android_sms_gateway.password' => 'android_pass',
            'services.smsgate.base_url' => 'https://sms-gate.app/api',
            'services.smsgate.username' => 'smsgate_user',
            'services.smsgate.password' => 'smsgate_pass',
        ]);

        Http::fake([
            'http://192.168.1.50:8080/message' => Http::response(['smsId' => 'android_sent'], 200),
            'https://sms-gate.app/api/3rdparty/v1/messages' => Http::response(['message_id' => 'smsgate_should_not_be_called'], 200),
        ]);

        $provider = app(SmsProvider::class);
        $result = $provider->sendSms('+970599000001', 'Test');

        $this->assertTrue($result['success']);
        $this->assertSame('android_sent', $result['message_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '192.168.1.50');
        });
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'sms-gate.app');
        });
    }
}
