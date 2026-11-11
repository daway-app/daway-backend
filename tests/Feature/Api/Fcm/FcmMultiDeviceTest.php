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
 * مستخدم بأكثر من جهاز: فشل جهاز لا يجوز أن يُسقط الباقي،
 * والتوكن الميت وحده يُحذف.
 */
class FcmMultiDeviceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_one_dead_device_does_not_block_the_others(): void
    {
        $user = User::factory()->patient()->create();

        $live = DeviceToken::create([
            'user_id' => $user->id, 'device_id' => 'phone-live',
            'platform' => 'android', 'token' => 'live-token',
        ]);

        $dead = DeviceToken::create([
            'user_id' => $user->id, 'device_id' => 'phone-dead',
            'platform' => 'android', 'token' => 'dead-token',
        ]);

        $unregistered = (new MessagingApiExceptionConverter)->convertResponse(new Response(404, [], json_encode([
            'error' => ['message' => 'Unregistered', 'details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                'errorCode' => 'UNREGISTERED',
            ]]],
        ])));

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturnUsing(
            function (Message $message, array $tokens) use ($unregistered) {
                $items = [];

                foreach ($tokens as $token) {
                    $target = MessageTarget::with(MessageTarget::TOKEN, $token);
                    $items[] = $token === 'dead-token'
                        ? SendReport::failure($target, $unregistered)
                        : SendReport::success($target, []);
                }

                return MulticastSendReport::withItems($items);
            },
        );
        $this->app->instance(Messaging::class, $messaging);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'order_status',
            'message' => 'تحديث على طلبك',
            'is_read' => false,
            'created_at' => now(),
        ]);

        app(FcmPushService::class)->fromNotification($notification);

        $notification->refresh();

        // الجهاز الحيّ لم يتأثر بفشل الآخر.
        $this->assertSame(FcmPushService::STATUS_SENT, $notification->push_status);
        $this->assertSame(1, $notification->push_attempts);
        $this->assertNotNull($notification->pushed_at);

        // الميت وحده حُذف، والحيّ بقي.
        $this->assertNull(DeviceToken::find($dead->id), 'التوكن الميت يُحذف');
        $this->assertNotNull(DeviceToken::find($live->id), 'التوكن السليم يبقى');

        // السجل نفسه لم يُحذف.
        $this->assertNotNull(Notification::find($notification->id));
    }

    public function test_all_devices_failing_marks_the_notification_for_retry(): void
    {
        $user = User::factory()->patient()->create();

        DeviceToken::create([
            'user_id' => $user->id, 'device_id' => 'a',
            'platform' => 'android', 'token' => 'token-a',
        ]);
        DeviceToken::create([
            'user_id' => $user->id, 'device_id' => 'b',
            'platform' => 'ios', 'token' => 'token-b',
        ]);

        $unavailable = (new MessagingApiExceptionConverter)->convertResponse(new Response(503, [], json_encode([
            'error' => ['message' => 'Unavailable', 'details' => [[
                '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                'errorCode' => 'UNAVAILABLE',
            ]]],
        ])));

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->once()->andReturnUsing(
            function (Message $message, array $tokens) use ($unavailable) {
                return MulticastSendReport::withItems(array_map(
                    fn ($token) => SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, $token), $unavailable),
                    $tokens,
                ));
            },
        );
        $this->app->instance(Messaging::class, $messaging);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'order_status',
            'message' => 'تحديث',
            'is_read' => false,
            'created_at' => now(),
        ]);

        app(FcmPushService::class)->fromNotification($notification);
        $notification->refresh();

        $this->assertSame(FcmPushService::STATUS_FAILED, $notification->push_status);
        $this->assertSame('UNAVAILABLE', $notification->push_error_code);

        // انقطاع مؤقت لا يجوز أن يحذف التوكنات السليمة.
        $this->assertSame(2, DeviceToken::where('user_id', $user->id)->count());
    }
}
