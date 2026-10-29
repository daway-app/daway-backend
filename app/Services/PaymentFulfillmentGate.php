<?php

namespace App\Services;

use App\Models\Order;

/**
 * Payment phase — the only gate between a confirmed order and fulfillment.
 *
 * Dawak has no cash-on-delivery flow. An order may enter or continue its
 * fulfillment lifecycle only while the parent order is explicitly `paid`;
 * the sub-order `payment_status` column is a display mirror and is never used
 * for authorization.
 */
final class PaymentFulfillmentGate
{
    public const ERROR_CODE = 'payment_required';

    /** Target states that require a paid parent order. */
    public const BLOCKED_TARGETS = [
        Order::STATUS_PREPARING,
        Order::STATUS_READY,
        Order::STATUS_PICKED_UP,
        Order::STATUS_OUT_FOR_DELIVERY,
        Order::STATUS_DELIVERED,
    ];

    public function isPaid(Order $order): bool
    {
        return $order->payment_status === Order::PAYMENT_PAID;
    }

    /**
     * @return array{ok: false, status: int, message: string, data: array{code: string, payment_status: ?string}}|null
     */
    public function denialFor(Order $order, ?string $targetStatus = null): ?array
    {
        if ($this->isPaid($order)) {
            return null;
        }

        if ($targetStatus !== null && ! in_array($targetStatus, self::BLOCKED_TARGETS, true)) {
            return null;
        }

        return [
            'ok' => false,
            'status' => 422,
            'message' => 'لا يمكن بدء أو متابعة تجهيز الطلب قبل تأكيد الدفع.',
            'data' => [
                'code' => self::ERROR_CODE,
                'payment_status' => $order->payment_status,
            ],
        ];
    }
}
