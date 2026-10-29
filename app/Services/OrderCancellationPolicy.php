<?php

namespace App\Services;

use App\Models\Order;

/**
 * Phase 2 — parent-order cancellation guard.
 *
 * No order-level refund workflow exists yet, so a paid order cannot be cancelled
 * without an explicit financial resolution. The policy is the single source of
 * truth consulted inside the cancellation transaction so it cannot be bypassed.
 */
final class OrderCancellationPolicy
{
    public const COMPLETED_CODE = 'cannot_cancel_completed';
    public const REFUND_CODE = 'refund_required';
    public const IN_TRANSIT_CODE = 'cannot_cancel_in_transit';

    /**
     * @return array{ok: false, status: int, message: string, data: array{code: string, payment_status: ?string, status: string}}|null
     */
    public function denialForCancel(Order $order): ?array
    {
        // 5. Delivered / already cancelled -> terminal, nothing to do.
        if ($order->isExpired()) {
            return $this->denial(self::COMPLETED_CODE, $order);
        }

        // 2. Paid order: require an explicit refund resolution before any
        //    cancellation/return.
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return $this->denial(self::REFUND_CODE, $order);
        }

        // 4. Already in the rider's hands (picked up / out for delivery) — do not
        //    restore inventory and do not allow ordinary cancellation.
        if ($order->hasStartedPickup()) {
            return $this->denial(self::IN_TRANSIT_CODE, $order);
        }

        return null;
    }

    public function allowsCancel(Order $order): bool
    {
        return $this->denialForCancel($order) === null;
    }

    /**
     * @return array{ok: false, status: int, message: string, data: array{code: string, payment_status: ?string, status: string}}
     */
    private function denial(string $code, Order $order): array
    {
        $messages = [
            self::COMPLETED_CODE => 'لا يمكن إلغاء طلب مُنجز أو ملغى.',
            self::REFUND_CODE => 'لا يمكن إلغاء طلب تم دفعه دفعًا كاملاً دون استرداد.',
            self::IN_TRANSIT_CODE => 'لا يمكن إلغاء الطلب في مرحلة التوصيل — يرجى التواصل مع الدعم.',
        ];

        return [
            'ok' => false,
            'status' => 422,
            'message' => $messages[$code],
            'data' => [
                'code' => $code,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
            ],
        ];
    }
}
