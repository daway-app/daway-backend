<?php

namespace Tests\Unit\Services;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingApiExceptionConverter;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\RequestFactory;
use PHPUnit\Framework\TestCase;

class FcmHttpV1ContractTest extends TestCase
{
    public function test_installed_sdk_builds_the_expected_http_v1_endpoint_and_payload(): void
    {
        $factory = new HttpFactory();
        $request = (new RequestFactory($factory, $factory))->createRequest(
            CloudMessage::fromArray(['token' => 'synthetic-token'])
                ->withNotification(['title' => 'Test', 'body' => 'Safe preview'])
                ->withData(['notification_id' => '42']),
            'test-project', false
        );

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://fcm.googleapis.com/v1/projects/test-project/messages:send', (string) $request->getUri());
        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('synthetic-token', $payload['message']['token']);
        $this->assertSame('42', $payload['message']['data']['notification_id']);
        $this->assertArrayNotHasKey('validate_only', $payload);
    }

    public function test_http_error_parser_preserves_fcm_detail_separately_from_http_status(): void
    {
        $error = (new MessagingApiExceptionConverter())->convertResponse(new Response(404, [], json_encode([
            'error' => ['status' => 'NOT_FOUND', 'message' => 'Unregistered', 'details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
            ]]],
        ])));

        $this->assertInstanceOf(NotFound::class, $error);
        $this->assertSame('UNREGISTERED', $error->errors()['error']['details'][0]['errorCode']);
    }

    public function test_credentials_error_is_not_an_expired_device_token(): void
    {
        $error = (new MessagingApiExceptionConverter())->convertResponse(new Response(401, [], json_encode([
            'error' => ['status' => 'UNAUTHENTICATED', 'message' => 'Authentication failed'],
        ])));

        $this->assertInstanceOf(AuthenticationError::class, $error);
        $this->assertArrayNotHasKey('details', $error->errors()['error']);
    }

    /**
     * Regression guard for the silent FCM outage: `FcmPushService` referenced
     * `MulticastMessage` / `sendEachForMulticast`, neither of which exists in the
     * installed SDK. The resulting Error was swallowed by `catch (Throwable)`, so
     * every push failed with no crash and no failing test. Guard the source text
     * itself, because a behavioural test cannot see a class that never resolves.
     */
    public function test_push_service_only_references_symbols_the_installed_sdk_provides(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/Fcm/FcmPushService.php');
        $this->assertIsString($source);

        $this->assertStringNotContainsString('MulticastMessage', $source);
        $this->assertStringNotContainsString('sendEachForMulticast', $source);
        $this->assertStringContainsString('CloudMessage', $source);
        $this->assertStringContainsString('sendMulticast', $source);

        $this->assertTrue(method_exists(\Kreait\Firebase\Contract\Messaging::class, 'sendMulticast'));
        $this->assertFalse(class_exists('Kreait\Firebase\Messaging\MulticastMessage'));
    }
}
