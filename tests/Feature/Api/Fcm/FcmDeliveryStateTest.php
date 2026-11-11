<?php

namespace Tests\Feature\Api\Fcm;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\Fcm\FcmPushService;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingApiExceptionConverter;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

/**
 * حالة تسليم الـpush على صف الإشعار + إعادة المحاولة.
 *
 * تحرس:
 *   1. نجاح الإرسال يُسجَّل `sent` مع `pushed_at`.
 *   2. فشل كامل يُسجَّل `failed` مع رمز الخطأ وعدد المحاولات.
 *   3. غياب الأجهزة يُسجَّل `skipped` (ليس فشلًا).
 *   4. أمر `fcm:retry-failed` يعيد الإرسال ويتوقف عند الحد الأقصى.
 */
class FcmDeliveryStateTest extends TestCase
{
    private function device(User $user, string $token = 'synthetic-test-token'): DeviceToken
    {
        return DeviceToken::create([
            'user_id' => $user->id,
            'device_id' => 'test-device',
            'platform' => 'android',
            'token' => $token,
        ]);
    }

    private function notification(User $user): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => 'order_status',
            'message' => 'تحديث على طلبك',
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    /** @return Messaging&Mockery\MockInterface */
    private function messagingReturning(int $successes, int $failures, string $errorCode = 'UNAVAILABLE')
    {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andReturnUsing(
            function (Message $message, array $tokens) use ($successes, $failures, $errorCode) {
                $reports = [];
                $i = 0;

                for ($s = 0; $s < $successes; $s++) {
                    $reports[] = SendReport::success(MessageTarget::with(MessageTarget::TOKEN, $tokens[$i++] ?? 'x'), []);
                }

                if ($failures > 0) {
                    $error = (new MessagingApiExceptionConverter)->convertResponse(new Response(503, [], json_encode([
                        'error' => [
                            'message' => 'Unavailable',
                            'details' => [[
                                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                                'errorCode' => $errorCode,
                            ]],
                        ],
                    ])));

                    for ($f = 0; $f < $failures; $f++) {
                        $reports[] = SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, $tokens[$i++] ?? 'y'), $error);
                    }
                }

                return MulticastSendReport::withItems($reports);
            },
        );

        $this->app->instance(Messaging::class, $messaging);

        return $messaging;
    }

    public function test_a_successful_push_records_sent_state(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user);
        $this->messagingReturning(1, 0);

        $notification = $this->notification($user);
        app(FcmPushService::class)->fromNotification($notification);

        $notification->refresh();
        $this->assertSame(FcmPushService::STATUS_SENT, $notification->push_status);
        $this->assertSame(1, $notification->push_attempts);
        $this->assertNotNull($notification->pushed_at);
    }

    public function test_a_failed_push_records_failed_state_and_keeps_the_row(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user);
        $this->messagingReturning(0, 1);

        $notification = $this->notification($user);
        app(FcmPushService::class)->fromNotification($notification);

        $notification->refresh();
        $this->assertSame(FcmPushService::STATUS_FAILED, $notification->push_status);
        $this->assertSame(1, $notification->push_attempts);
        $this->assertSame('UNAVAILABLE', $notification->push_error_code);
        $this->assertNotNull(Notification::find($notification->id), 'السجل يبقى حتى عند فشل Firebase');
    }

    public function test_no_devices_is_recorded_as_skipped_not_failed(): void
    {
        $user = User::factory()->patient()->create();

        $notification = $this->notification($user);
        app(FcmPushService::class)->fromNotification($notification);

        $notification->refresh();
        $this->assertSame(FcmPushService::STATUS_SKIPPED, $notification->push_status);
    }

    public function test_the_retry_command_resends_failed_notifications(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user);
        $this->messagingReturning(0, 1);

        $notification = $this->notification($user);
        app(FcmPushService::class)->fromNotification($notification);
        $this->assertSame(FcmPushService::STATUS_FAILED, $notification->fresh()->push_status);

        // الآن ينجح الإرسال.
        $this->messagingReturning(1, 0);

        $this->artisan('fcm:retry-failed')->assertSuccessful();

        $notification->refresh();
        $this->assertSame(FcmPushService::STATUS_SENT, $notification->push_status);
        $this->assertSame(2, $notification->push_attempts);
    }

    public function test_the_retry_command_stops_at_the_attempt_ceiling(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user);
        $this->messagingReturning(0, 1);

        $notification = $this->notification($user);
        $notification->forceFill(['push_status' => FcmPushService::STATUS_FAILED, 'push_attempts' => 3])->save();

        $this->messagingReturning(1, 0);
        $this->artisan('fcm:retry-failed', ['--max' => 3])->assertSuccessful();

        // لم تُحاول: وصلت للحد الأقصى.
        $this->assertSame(3, $notification->fresh()->push_attempts);
        $this->assertSame(FcmPushService::STATUS_FAILED, $notification->fresh()->push_status);
    }

    public function test_sent_notifications_are_never_retried(): void
    {
        $user = User::factory()->patient()->create();
        $this->device($user);

        $notification = $this->notification($user);
        $notification->forceFill(['push_status' => FcmPushService::STATUS_SENT, 'push_attempts' => 1])->save();

        $this->messagingReturning(1, 0);
        $this->artisan('fcm:retry-failed')->assertSuccessful();

        $this->assertSame(1, $notification->fresh()->push_attempts);
    }
}
