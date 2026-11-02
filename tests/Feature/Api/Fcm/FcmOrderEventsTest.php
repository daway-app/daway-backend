<?php

namespace Tests\Feature\Api\Fcm;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\OrderNotificationService;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class FcmOrderEventsTest extends TestCase
{
    use BuildsDeliveryOrders;

    public function test_order_events_reach_the_sdk_once_for_each_intended_recipient_without_a_worker(): void
    {
        $this->bootDeliveryWorld();
        // Database queue configured deliberately: push must still happen inline.
        config(['queue.default' => 'database']);
        Queue::fake();
        $users = [$this->patient, $this->pharmacyA->user, $this->pharmacyB->user, $this->admin];
        $unrelated = User::factory()->pharmacy()->create();
        $users[] = $unrelated;
        foreach ($users as $user) {
            DeviceToken::create([
                'user_id' => $user->id, 'device_id' => 'event-device-'.$user->id,
                'platform' => 'android', 'token' => 'synthetic-user-'.$user->id,
            ]);
        }
        $calls = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andReturnUsing(function (Message $message, array $tokens) use (&$calls) {
            $payload = $message->jsonSerialize();
            $notification = Notification::findOrFail($payload['data']['notification_id']);
            $this->assertSame(['synthetic-user-'.$notification->user_id], $tokens);
            $this->assertSame((string) $notification->order_id, $payload['data']['order_id']);
            $this->assertSame((string) $notification->sub_order_id, $payload['data']['sub_order_id']);
            $calls[] = $notification->id;
            return MulticastSendReport::withItems([
                SendReport::success(MessageTarget::with('token', $tokens[0]), ['name' => 'projects/test/messages/test']),
            ]);
        });
        $this->app->instance(Messaging::class, $messaging);

        $order = $this->checkoutTwoPharmacy();
        $service = app(OrderNotificationService::class);
        $service->orderCreated($order); // Repeating the event must not push again.
        $service->receiptSubmitted($order, 17);
        $service->receiptSubmitted($order, 17);
        foreach ($order->subOrders as $subOrder) {
            $service->subOrderPreparing($order, $subOrder);
            $service->subOrderPreparing($order, $subOrder);
            $service->subOrderReady($order, $subOrder);
            $service->subOrderReady($order, $subOrder);
        }
        $service->orderOutForDelivery($order);
        $service->orderOutForDelivery($order);
        $service->orderDelivered($order);
        $service->orderDelivered($order);

        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('type', 'order_payment_receipt_submitted')->count());
        $this->assertSame(0, Notification::where('user_id', $unrelated->id)->count());
        $this->assertCount(Notification::count(), $calls);
        $this->assertCount(count($calls), array_unique($calls));
        foreach (['order_created', 'order_preparing', 'order_ready', 'order_out_for_delivery', 'order_delivered'] as $type) {
            $this->assertTrue(Notification::where('user_id', $this->patient->id)->where('type', $type)->exists());
        }
        Queue::assertNothingPushed();
    }
}
