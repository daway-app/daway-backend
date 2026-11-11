<?php

namespace App\Services;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderNotificationService
{
    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['user', 'subOrders.pharmacy.user', 'pharmacy.user']);

        $this->notifyUser(
            $order->user,
            'order_created',
            "تم إنشاء الطلب #{$order->id} بنجاح.",
            $order,
            null,
            "order:{$order->id}:created:patient"
        );

    }

    /**
     * Notify each pharmacy only after an administrator has approved payment.
     * Checkout and receipt submission must never notify pharmacies.
     */
    public function pharmacyOrderPaymentApproved(Order $order): void
    {
        if ($order->payment_status !== Order::PAYMENT_PAID || $order->status === Order::STATUS_CANCELLED) {
            return;
        }

        $order->loadMissing(['subOrders.pharmacy.user', 'pharmacy.user']);

        foreach ($order->subOrders as $subOrder) {
            if ($subOrder->status === SubOrder::STATUS_CANCELLED) {
                continue;
            }

            $this->notifyUser(
                $subOrder->pharmacy?->user,
                'order_created',
                "وصل طلب جديد #{$order->id} إلى صيدليتك بعد اعتماد الدفع.",
                $order,
                $subOrder,
                "order:{$order->id}:sub:{$subOrder->id}:payment-approved:pharmacy"
            );
        }

        if ($order->subOrders->isEmpty()) {
            $this->notifyUser(
                $order->pharmacy?->user,
                'order_created',
                "وصل طلب جديد #{$order->id} إلى صيدليتك بعد اعتماد الدفع.",
                $order,
                null,
                "order:{$order->id}:payment-approved:pharmacy"
            );
        }
    }

    public function receiptSubmitted(Order $order, int $submissionId): void
    {
        $this->notifyAdmins(
            $order,
            'order_payment_receipt_submitted',
            "وصل إيصال تحويل جديد للطلب #{$order->id} بانتظار المراجعة.",
            "order:{$order->id}:receipt:{$submissionId}:admin"
        );
    }

    public function paymentApproved(Order $order, int $submissionId): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_payment_approved',
            "تم اعتماد دفع الطلب #{$order->id}.",
            $order,
            null,
            "order:{$order->id}:payment:{$submissionId}:approved:patient"
        );
    }

    public function paymentRejected(Order $order, int $submissionId): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_payment_rejected',
            "تم رفض إيصال الدفع للطلب #{$order->id}.",
            $order,
            null,
            "order:{$order->id}:payment:{$submissionId}:rejected:patient"
        );
    }

    public function paymentProblemReported(Order $order, int $problemId): void
    {
        $this->notifyAdmins(
            $order,
            'order_payment_problem_reported',
            "تم الإبلاغ عن مشكلة دفع في الطلب #{$order->id}.",
            "order:{$order->id}:problem:{$problemId}:reported:admin"
        );
    }

    public function paymentProblemResolved(Order $order, int $problemId): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_payment_problem_resolved',
            "تم حل مشكلة الدفع في الطلب #{$order->id}.",
            $order,
            null,
            "order:{$order->id}:problem:{$problemId}:resolved:patient"
        );
    }

    public function subOrderPreparing(Order $order, SubOrder $subOrder): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_preparing',
            "طلبك #{$order->id} قيد التجهيز لدى الصيدلية.",
            $order,
            $subOrder,
            "order:{$order->id}:sub:{$subOrder->id}:preparing:patient"
        );
    }

    public function subOrderReady(Order $order, SubOrder $subOrder): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_ready',
            "طلبك #{$order->id} جاهز من الصيدلية.",
            $order,
            $subOrder,
            "order:{$order->id}:sub:{$subOrder->id}:ready:patient"
        );

        if ($order->rider_id === null) {
            $this->notifyAdmins(
                $order,
                'order_ready',
                "الطلب #{$order->id} جاهز ولم يُسند إلى مندوب بعد.",
                "order:{$order->id}:ready-unassigned:admin"
            );
        }
    }

    public function orderCancelled(Order $order, ?SubOrder $subOrder = null): void
    {
        $order->loadMissing(['user', 'subOrders.pharmacy.user', 'rider']);

        $partial = $subOrder !== null;

        $this->notifyUser(
            $order->user,
            'order_cancelled',
            $partial
                ? "تم إلغاء جزء من طلبك #{$order->id}."
                : "تم إلغاء طلبك #{$order->id}.",
            $order,
            $subOrder,
            $partial
                ? "order:{$order->id}:sub:{$subOrder->id}:cancelled:patient"
                : "order:{$order->id}:cancelled:patient"
        );

        $pharmacies = $partial
            ? collect([$subOrder->pharmacy])
            : $order->subOrders->pluck('pharmacy');

        foreach ($pharmacies->filter()->unique('id') as $pharmacy) {
            $this->notifyUser(
                $pharmacy->user,
                'order_cancelled',
                "تم إلغاء الطلب #{$order->id} الخاص بصيدليتك.",
                $order,
                $partial ? $subOrder : null,
                $partial
                    ? "order:{$order->id}:sub:{$subOrder->id}:cancelled:pharmacy"
                    : "order:{$order->id}:cancelled:pharmacy"
            );
        }

        $this->notifyUser(
            $order->rider,
            'order_cancelled',
            "تم إلغاء الطلب #{$order->id} المسند إليك.",
            $order,
            $subOrder,
            $partial
                ? "order:{$order->id}:sub:{$subOrder->id}:cancelled:rider"
                : "order:{$order->id}:cancelled:rider"
        );
    }

    public function riderAssigned(Order $order, int $riderId, ?int $previousRiderId): void
    {
        if ($previousRiderId !== null && $previousRiderId === $riderId) {
            return;
        }

        $order->loadMissing('user');

        $rider = User::find($riderId);
        $eventKey = $order->assigned_at?->timestamp ?? now()->timestamp;

        $this->notifyUser(
            $rider,
            'order_rider_assigned',
            "تم إسناد الطلب #{$order->id} إليك.",
            $order,
            null,
            "order:{$order->id}:rider-assigned:{$riderId}:{$eventKey}"
        );

        $this->notifyUser(
            $order->user,
            'order_rider_assigned',
            "تم إسناد مندوب لتوصيل طلبك #{$order->id}.",
            $order,
            null,
            "order:{$order->id}:rider-assigned:patient:{$riderId}:{$eventKey}"
        );

        if ($previousRiderId !== null) {
            $previousRider = User::find($previousRiderId);

            $this->notifyUser(
                $previousRider,
                'order_rider_reassigned',
                "تم إعادة إسناد الطلب #{$order->id} إلى مندوب آخر.",
                $order,
                null,
                "order:{$order->id}:rider-reassigned:{$previousRiderId}:{$eventKey}"
            );
        }
    }

    public function riderUnassigned(Order $order, int $previousRiderId, ?string $previousAssignedAt = null): void
    {
        $order->loadMissing('user');

        $previousRider = User::find($previousRiderId);
        $eventKey = $previousAssignedAt ?? now()->toDateTimeString();

        $this->notifyUser(
            $previousRider,
            'order_rider_unassigned',
            "تم فك إسناد الطلب #{$order->id} عنك.",
            $order,
            null,
            "order:{$order->id}:rider-unassigned:{$previousRiderId}:{$eventKey}"
        );

        $this->notifyUser(
            $order->user,
            'order_rider_unassigned',
            "تم إلغاء إسناد مندوب الطلب #{$order->id}، وسيتم إسناد مندوب جديد.",
            $order,
            null,
            "order:{$order->id}:rider-unassigned:patient:{$previousRiderId}:{$eventKey}"
        );
    }

    public function riderPickedUp(Order $order, SubOrder $subOrder, bool $allPickedUp): void
    {
        $subOrder->loadMissing('pharmacy.user');

        $this->notifyUser(
            $subOrder->pharmacy?->user,
            'order_picked_up',
            "استلم المندوب الطلب الفرعي #{$subOrder->id} للطلب #{$order->id}.",
            $order,
            $subOrder,
            "order:{$order->id}:sub:{$subOrder->id}:picked-up:pharmacy"
        );

        if (! $allPickedUp) {
            return;
        }

        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_picked_up',
            "استلم المندوب طلبك #{$order->id} وهو في طريقه إليك.",
            $order,
            null,
            "order:{$order->id}:picked-up:patient"
        );
    }

    public function orderOutForDelivery(Order $order): void
    {
        $order->loadMissing('user');

        $this->notifyUser(
            $order->user,
            'order_out_for_delivery',
            "طلبك #{$order->id} في الطريق إليك.",
            $order,
            null,
            "order:{$order->id}:out-for-delivery:patient"
        );
    }

    public function orderDelivered(Order $order): void
    {
        $order->loadMissing(['user', 'subOrders.pharmacy.user', 'pharmacy.user']);

        $this->notifyUser(
            $order->user,
            'order_delivered',
            "تم تسليم طلبك #{$order->id} بنجاح.",
            $order,
            null,
            "order:{$order->id}:delivered:patient"
        );

        $pharmacies = $order->subOrders->isNotEmpty()
            ? $order->subOrders->pluck('pharmacy')
            : collect([$order->pharmacy]);

        foreach ($pharmacies->filter()->unique('id') as $pharmacy) {
            $this->notifyUser(
                $pharmacy->user,
                'order_delivered',
                "تم تسليم الطلب #{$order->id} الخاص بصيدليتك.",
                $order,
                null,
                "order:{$order->id}:delivered:pharmacy"
            );
        }
    }

    public function legacyOrderStatusChanged(Order $order, string $status): void
    {
        $order->loadMissing('user');

        if ($status === Order::STATUS_PREPARING) {
            $this->notifyUser(
                $order->user,
                'order_preparing',
                "طلبك #{$order->id} قيد التجهيز لدى الصيدلية.",
                $order,
                null,
                "order:{$order->id}:legacy:preparing:patient"
            );

            return;
        }

        if ($status === Order::STATUS_READY) {
            $this->notifyUser(
                $order->user,
                'order_ready',
                "طلبك #{$order->id} جاهز من الصيدلية.",
                $order,
                null,
                "order:{$order->id}:legacy:ready:patient"
            );

            if ($order->rider_id === null) {
                $this->notifyAdmins(
                    $order,
                    'order_ready',
                    "الطلب #{$order->id} جاهز ولم يُسند إلى مندوب بعد.",
                    "order:{$order->id}:ready-unassigned:admin"
                );
            }

            return;
        }

        match ($status) {
            Order::STATUS_OUT_FOR_DELIVERY => $this->orderOutForDelivery($order),
            Order::STATUS_DELIVERED => $this->orderDelivered($order),
            Order::STATUS_CANCELLED => $this->orderCancelled($order),
            default => null,
        };
    }

    private function notifyAdmins(Order $order, string $type, string $message, string $dedupeKey): void
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            $this->notifyUser($admin, $type, $message, $order, null, $dedupeKey);
        }
    }

    private function notifyUser(
        ?User $user,
        string $type,
        string $message,
        Order $order,
        ?SubOrder $subOrder,
        string $dedupeKey
    ): void {
        if (! $user) {
            return;
        }

        try {
            $notification = Notification::firstOrCreate(
                ['dedupe_key' => $dedupeKey.':user:'.$user->id],
                [
                    'user_id' => $user->id,
                    'medicine_id' => null,
                    'order_id' => $order->id,
                    'sub_order_id' => $subOrder?->id,
                    'type' => $type,
                    'message' => $message,
                    'is_read' => false,
                    'created_at' => now(),
                ]
            );

            if (! $notification->wasRecentlyCreated) {
                return;
            }

            try {
                app(FcmSender::class)->fromNotification($notification);
            } catch (Throwable $e) {
                Log::warning('order_notification_push_failed', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('order_notification_failed', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
