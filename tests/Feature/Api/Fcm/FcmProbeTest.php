<?php

namespace Tests\Feature\Api\Fcm;

use App\Console\Commands\FcmProbe;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingApiExceptionConverter;
use Kreait\Firebase\Messaging\Message;
use Mockery;
use Tests\TestCase;

/**
 * أمر `fcm:probe` — التحقق من HTTP v1 بـvalidate_only دون تسليم.
 *
 * يحرس:
 *   1. كل استدعاء يمرّ بـ`validateOnly = true` (لا تسليم أبدًا).
 *   2. الموضوع الوهمي افتراضيًا، والتوكن عند طلبه صراحةً.
 *   3. تمييز فشل الاعتماد عن رفض الرسالة برموز خروج مختلفة.
 *   4. عدم طباعة التوكن ولا رسالة الاستثناء.
 */
class FcmProbeTest extends TestCase
{
    private function mockMessaging(): Mockery\MockInterface
    {
        $messaging = Mockery::mock(Messaging::class);
        $this->app->instance(Messaging::class, $messaging);

        return $messaging;
    }

    public function test_the_probe_validates_without_delivering(): void
    {
        $captured = null;

        $this->mockMessaging()
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (Message $message, $validateOnly) use (&$captured) {
                $captured = ['message' => $message->jsonSerialize(), 'validateOnly' => $validateOnly];

                return true;
            })
            ->andReturn(['name' => 'projects/demo/messages/1']);

        $this->artisan('fcm:probe')
            ->assertExitCode(FcmProbe::OK)
            ->expectsOutputToContain('validated');

        // الشرط الجوهري: لا تسليم.
        $this->assertTrue($captured['validateOnly'], 'validate_only يجب أن يكون مفعّلًا دائمًا');
        $this->assertSame('daway-probe', $captured['message']['topic']);
    }

    public function test_the_probe_uses_a_token_only_when_explicitly_given(): void
    {
        $captured = null;

        $this->mockMessaging()
            ->shouldReceive('send')
            ->once()
            ->withArgs(function (Message $message, bool $validateOnly) use (&$captured) {
                $captured = ['message' => $message->jsonSerialize(), 'validateOnly' => $validateOnly];

                return true;
            })
            ->andReturn(['name' => 'projects/demo/messages/2']);

        $this->artisan('fcm:probe', ['--token' => 'synthetic-probe-token'])
            ->assertExitCode(FcmProbe::OK)
            ->doesntExpectOutputToContain('synthetic-probe-token');

        $this->assertTrue($captured['validateOnly']);
        $this->assertSame('synthetic-probe-token', $captured['message']['token']);
    }

    public function test_auth_failure_has_its_own_exit_code(): void
    {
        $error = (new MessagingApiExceptionConverter)->convertResponse(new Response(403, [], json_encode([
            'error' => ['status' => 'UNAUTHENTICATED', 'message' => 'Request had invalid authentication credentials.'],
        ])));

        $this->mockMessaging()
            ->shouldReceive('send')
            ->once()
            ->andThrow($error);

        $this->artisan('fcm:probe')
            ->assertExitCode(FcmProbe::AUTH_FAILED)
            ->expectsOutputToContain('auth_failed');
    }

    public function test_a_rejected_message_has_its_own_exit_code(): void
    {
        $error = (new MessagingApiExceptionConverter)->convertResponse(new Response(400, [], json_encode([
            'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid message.'],
        ])));

        $this->mockMessaging()
            ->shouldReceive('send')
            ->once()
            ->andThrow($error);

        $this->artisan('fcm:probe')
            ->assertExitCode(FcmProbe::MESSAGE_REJECTED)
            ->expectsOutputToContain('message_rejected');
    }

    public function test_a_missing_sdk_is_reported_without_leaking_the_exception(): void
    {
        $this->app->bind(Messaging::class, function () {
            throw new \RuntimeException('credentials file /etc/secrets/secret.json is unreadable');
        });

        $this->artisan('fcm:probe')
            ->assertExitCode(FcmProbe::SDK_UNAVAILABLE)
            ->doesntExpectOutputToContain('/etc/secrets');
    }
}
