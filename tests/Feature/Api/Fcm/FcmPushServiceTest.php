<?php

namespace Tests\Feature\Api\Fcm;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\Fcm\FcmPushService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingApiExceptionConverter;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

class FcmPushServiceTest extends TestCase
{
    private function device(User $user, string $token, string $device = 'test-device'): DeviceToken
    {
        return DeviceToken::create([
            'user_id' => $user->id, 'device_id' => $device,
            'platform' => 'android', 'token' => $token,
        ]);
    }

    public function test_uses_installed_sdk_to_send_to_a_registered_device(): void
    {
        $user = User::factory()->patient()->create();
        DeviceToken::create([
            'user_id' => $user->id,
            'device_id' => 'test-device',
            'platform' => 'android',
            'token' => 'synthetic-test-token',
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()
            ->with(Mockery::on(function (Message $message) {
                $payload = $message->jsonSerialize();
                return $payload['notification']['body'] === 'Safe preview'
                    && $payload['data']['notification_id'] === '42';
            }), ['synthetic-test-token'])
            ->andReturn(MulticastSendReport::withItems([
                SendReport::success(MessageTarget::with('token', 'synthetic-test-token'), [
                    'name' => 'projects/test/messages/synthetic-message',
                ]),
            ]));
        $this->app->instance(Messaging::class, $messaging);

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview', ['notification_id' => 42]);
    }

    public function test_only_confirmed_dead_tokens_are_pruned_from_http_v1_errors(): void
    {
        $user = User::factory()->patient()->create();
        $cases = [
            ['expired', 404, 'UNREGISTERED', true, []],
            ['bad-token', 400, 'INVALID_ARGUMENT', true, [
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => [['field' => 'message.token', 'description' => 'Invalid token']]],
            ]],
            ['bad-payload', 400, 'INVALID_ARGUMENT', false, []],
            ['wrong-project', 403, 'SENDER_ID_MISMATCH', false, []],
            ['auth-failure', 401, null, false, []],
            ['quota', 429, 'QUOTA_EXCEEDED', false, []],
            ['temporary', 503, 'UNAVAILABLE', false, []],
            ['unknown-404', 404, null, false, []],
        ];
        $items = [];
        foreach ($cases as [$token, $httpStatus, $fcmCode, $prune, $extraDetails]) {
            $this->device($user, $token, $token);
            $details = $extraDetails;
            if ($fcmCode !== null) {
                $details[] = ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $fcmCode];
            }
            $error = (new MessagingApiExceptionConverter())->convertResponse(new Response(
                $httpStatus, ['Content-Type' => 'application/json'],
                json_encode(['error' => ['code' => $httpStatus, 'message' => 'Sensitive error text', 'details' => $details]])
            ));
            $items[] = SendReport::failure(MessageTarget::with('token', $token), $error);
        }
        $messaging = Mockery::mock(Messaging::class);
        // Report order is deliberately different from database order.
        $messaging->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems(array_reverse($items)));
        $this->app->instance(Messaging::class, $messaging);

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview');

        foreach ($cases as [$token, , , $prune]) {
            $this->assertSame(! $prune, DeviceToken::where('token', $token)->exists(), $token);
        }
    }

    public function test_network_failure_keeps_tokens_and_logs_no_exception_text(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user, 'synthetic-private-token');
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andThrow(new \RuntimeException('synthetic-private-token private-key-like-text'));
        $this->app->instance(Messaging::class, $messaging);
        Log::spy();

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview');

        $this->assertSame(1, DeviceToken::count());
        Log::shouldHaveReceived('warning')->with('fcm_push_failed', Mockery::on(
            fn (array $context) => ($context['exception'] ?? null) === \RuntimeException::class
                && ! str_contains(json_encode($context), 'synthetic-private-token')
                && ! str_contains(json_encode($context), 'private-key-like-text')
        ));
    }

    public function test_a_token_refreshed_during_send_is_not_deleted_by_an_old_report(): void
    {
        $user = User::factory()->patient()->create();
        $device = $this->device($user, 'expired-token');
        $error = (new MessagingApiExceptionConverter())->convertResponse(new Response(404, [], json_encode([
            'error' => ['message' => 'Unregistered', 'details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
            ]]],
        ])));
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturnUsing(function () use ($device, $error) {
            $device->update(['token' => 'fresh-token']);
            return MulticastSendReport::withItems([SendReport::failure(MessageTarget::with('token', 'expired-token'), $error)]);
        });
        $this->app->instance(Messaging::class, $messaging);

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview');

        $this->assertDatabaseHas('device_tokens', ['id' => $device->id, 'token' => 'fresh-token']);
    }

    public function test_database_message_stays_private_and_notification_ids_remain_routable(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user, 'privacy-test-token');
        $notification = Notification::create([
            'user_id' => $user->id, 'type' => 'reminder', 'message' => 'Sensitive medicine name and dosage',
            'is_read' => false, 'created_at' => now(),
        ]);
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->with(Mockery::on(function (Message $message) use ($notification) {
            $payload = $message->jsonSerialize();
            $this->assertSame('إشعار جديد من دواك', $payload['notification']['title']);
            $this->assertSame('لديك تحديث جديد. افتح التطبيق للاطلاع عليه.', $payload['notification']['body']);
            $this->assertSame((string) $notification->id, $payload['data']['notification_id']);
            $this->assertStringNotContainsString('Sensitive medicine', json_encode($payload));
            return true;
        }), ['privacy-test-token'])->andReturn(MulticastSendReport::withItems([]));
        $this->app->instance(Messaging::class, $messaging);

        app(FcmPushService::class)->fromNotification($notification);

        $this->assertSame('Sensitive medicine name and dosage', $notification->fresh()->message);
    }

    public function test_missing_configuration_is_logged_without_details_and_database_notification_survives(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user, 'configuration-test-token');
        $this->app->bind(Messaging::class, fn () => throw new \RuntimeException('secret credential details'));
        Log::spy();

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview');

        $this->assertSame(1, DeviceToken::count());
        Log::shouldHaveReceived('warning')->with('fcm_unavailable', ['exception' => \RuntimeException::class]);
    }

    public function test_no_device_tokens_does_not_resolve_firebase_or_make_a_request(): void
    {
        $user = User::factory()->patient()->create();
        $resolved = false;
        $this->app->bind(Messaging::class, function () use (&$resolved) {
            $resolved = true;
            throw new \RuntimeException('Unexpected Firebase resolution');
        });

        app(FcmPushService::class)->sendToUser($user, 'Test', 'Safe preview');
        $this->assertFalse($resolved);
        $this->assertSame(0, DeviceToken::count());
    }
}
