<?php

namespace Tests\Feature\Api\Notifications;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentBank;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class OrderNotificationFlowTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->bootDeliveryWorld();
    }

    public function test_checkout_receipt_and_approval_notify_the_right_recipients_once(): void
    {
        $bank = $this->bank();
        $order = $this->checkoutTwoPharmacy();

        $this->assertSame(1, $this->countFor($this->patient, 'order_created'));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->patient->id,
            'type' => 'order_created',
            'order_id' => $order->id,
            'sub_order_id' => null,
        ]);

        foreach ([$this->pharmacyA, $this->pharmacyB] as $pharmacy) {
            $subOrder = $this->subOrderFor($order, $pharmacy);

            $this->assertDatabaseHas('notifications', [
                'user_id' => $pharmacy->user_id,
                'type' => 'order_created',
                'order_id' => $order->id,
                'sub_order_id' => $subOrder->id,
            ]);
        }

        $this->submitReceipt($order, $bank)->assertCreated();

        $this->assertSame(1, $this->countFor($this->admin, 'order_payment_receipt_submitted'));

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/payments/{$order->id}/approve")->assertOk();
        $this->postJson("/api/admin/payments/{$order->id}/approve")->assertOk();

        $this->assertSame(1, $this->countFor($this->patient, 'order_payment_approved'));

        $this->actingAs($this->patient, 'sanctum');
        $this->getJson('/api/notifications?type=order_payment_approved')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.sub_order_id', null);
    }

    public function test_dispatch_reassign_and_unassign_notify_the_rider_and_patient(): void
    {
        $order = $this->readyMultiPharmacyOrder();

        $this->assignAsAdmin($order, $this->rider);

        $this->assertSame(1, $this->countFor($this->rider, 'order_rider_assigned'));
        $this->assertSame(1, $this->countFor($this->patient, 'order_rider_assigned'));

        $this->assignAsAdmin($order, $this->riderTwo);

        $this->assertSame(1, $this->countFor($this->riderTwo, 'order_rider_assigned'));
        $this->assertSame(1, $this->countFor($this->rider, 'order_rider_reassigned'));
        $this->assertSame(2, $this->countFor($this->patient, 'order_rider_assigned'));

        $this->actingAs($this->admin, 'sanctum');
        $this->deleteJson("/api/admin/orders/{$order->id}/assign-rider")->assertOk();

        $this->assertSame(1, $this->countFor($this->riderTwo, 'order_rider_unassigned'));
        $this->assertSame(1, $this->countFor($this->patient, 'order_rider_unassigned'));
        $this->assertNull($order->fresh()->rider_id);
    }

    public function test_pickup_delivery_and_delivered_notifications_follow_the_lifecycle(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        $subA = $this->subOrderFor($order, $this->pharmacyA);
        $subB = $this->subOrderFor($order, $this->pharmacyB);

        $this->actingAs($this->rider, 'sanctum');
        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $subA->id,
        ])->assertOk();

        $this->assertSame(1, $this->countFor($this->pharmacyA->user, 'order_picked_up'));
        $this->assertSame(0, $this->countFor($this->pharmacyB->user, 'order_picked_up'));
        $this->assertSame(0, $this->countFor($this->patient, 'order_picked_up'));

        $this->postJson("/api/rider/orders/{$order->id}/pickup", [
            'sub_order_id' => $subB->id,
        ])->assertOk();

        $this->assertSame(1, $this->countFor($this->pharmacyB->user, 'order_picked_up'));
        $this->assertSame(1, $this->countFor($this->patient, 'order_picked_up'));

        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => Order::STATUS_OUT_FOR_DELIVERY,
        ])->assertOk();

        $this->assertSame(1, $this->countFor($this->patient, 'order_out_for_delivery'));

        $this->postJson("/api/rider/orders/{$order->id}/status", [
            'status' => Order::STATUS_DELIVERED,
        ])->assertOk();

        $this->assertSame(1, $this->countFor($this->patient, 'order_delivered'));
        $this->assertSame(1, $this->countFor($this->pharmacyA->user, 'order_delivered'));
        $this->assertSame(1, $this->countFor($this->pharmacyB->user, 'order_delivered'));
    }

    public function test_cancellation_notifies_patient_pharmacies_and_assigned_rider(): void
    {
        $order = $this->readyMultiPharmacyOrder();
        $this->assignAsAdmin($order, $this->rider);

        // Cancellation notifies stakeholders; use an unpaid, not-yet-picked-up
        // order so the Phase 2 guard permits it.
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);

        $this->actingAs($this->patient, 'sanctum');
        $this->postJson("/api/patient/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(1, $this->countFor($this->patient, 'order_cancelled'));
        $this->assertSame(1, $this->countFor($this->pharmacyA->user, 'order_cancelled'));
        $this->assertSame(1, $this->countFor($this->pharmacyB->user, 'order_cancelled'));
        $this->assertSame(1, $this->countFor($this->rider, 'order_cancelled'));
    }

    public function test_rider_can_register_a_device_token_for_push_notifications(): void
    {
        $this->actingAs($this->rider, 'sanctum');

        $this->postJson('/api/device-tokens', [
            'token' => 'fcm-token-'.uniqid(),
            'platform' => 'android',
            'device_id' => 'rider-device-1',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $this->rider->id);
    }

    private function countFor(User $user, string $type): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->count();
    }

    private function bank(): PaymentBank
    {
        return PaymentBank::create([
            'bank_name' => 'بنك الاختبار',
            'account_holder' => 'دواوي',
            'account_number' => '1234567890',
            'iban' => 'PS92PALS0000000001234567890',
            'branch' => 'رام الله',
            'instructions' => 'أرسل صورة الإيصال.',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function submitReceipt(Order $order, PaymentBank $bank): TestResponse
    {
        $this->actingAs($this->patient, 'sanctum');

        return $this->post("/api/patient/orders/{$order->id}/payment/receipt", [
            'bank_id' => $bank->id,
            'amount' => (float) $order->total,
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 1200)->size(200),
            'sender_name' => 'المريض',
            'transfer_reference' => 'TRX-'.uniqid(),
            'transferred_at' => now()->toDateString(),
        ], ['Accept' => 'application/json']);
    }
}
