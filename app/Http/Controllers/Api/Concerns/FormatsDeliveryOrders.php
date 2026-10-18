<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;

/**
 * Rider Delivery — بناة الحِزم (payloads) بأسلوب المشروع (مصفوفات يدوية).
 * مشتركة بين كنترولر إدارة الطلبات وكنترولر المندوب لتفادي تكرار الأسماء.
 */
trait FormatsDeliveryOrders
{
    /** @return array<string, mixed> */
    protected function deliverySubOrderPayload(SubOrder $subOrder): array
    {
        return [
            'sub_order_id' => $subOrder->id,
            'pharmacy_id' => $subOrder->pharmacy_id,
            'pharmacy_name' => $subOrder->pharmacy?->pharmacy_name,
            'status' => $subOrder->status,
            'picked_up_at' => $subOrder->picked_up_at?->toDateTimeString(),
            'delivered_at' => $subOrder->delivered_at?->toDateTimeString(),
            'total' => (float) $subOrder->total,
            'items_count' => $subOrder->items->count(),
            'items' => collect($subOrder->items)->map(fn ($item) => [
                'medicine_trade_name' => $item->medicine_trade_name,
                'quantity' => (int) $item->quantity,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    protected function deliveryUserBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    /**
     * حزمة الطلب للمندوب: ما يحتاجه لتجميع الطلب من الصيدليات وإيصاله للمريض.
     *
     * @return array<string, mixed>
     */
    protected function riderOrderPayload(Order $order): array
    {
        $active = collect($order->subOrders)
            ->reject(fn (SubOrder $s) => $s->status === SubOrder::STATUS_CANCELLED);

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'delivery_fee' => (float) $order->delivery_fee,
            'grand_total' => round((float) $order->total + (float) $order->delivery_fee, 2),
            'address_snapshot' => $order->address_snapshot,
            'assigned_at' => $order->assigned_at?->toDateTimeString(),
            'picked_up_at' => $order->picked_up_at?->toDateTimeString(),
            'delivered_at' => $order->delivered_at?->toDateTimeString(),
            'all_picked_up' => $active->isNotEmpty()
                && $active->every(fn (SubOrder $s) => $s->picked_up_at !== null),
            'is_multi_pharmacy' => $order->subOrders->count() > 1,
            'sub_orders' => collect($order->subOrders)
                ->map(fn (SubOrder $s) => $this->deliverySubOrderPayload($s))
                ->values(),
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }

    /**
     * حزمة الطلب الإدارية: المريض + المندوب + الصيدليات + التدقيق.
     *
     * @return array<string, mixed>
     */
    protected function adminOrderPayload(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'delivery_fee' => (float) $order->delivery_fee,
            'grand_total' => round((float) $order->total + (float) $order->delivery_fee, 2),
            'is_multi_pharmacy' => $order->subOrders->count() > 1,
            'patient' => $this->deliveryUserBrief($order->user),
            'rider' => $this->deliveryUserBrief($order->rider),
            'assigned_by' => $this->deliveryUserBrief($order->assignedBy),
            'assigned_at' => $order->assigned_at?->toDateTimeString(),
            'picked_up_at' => $order->picked_up_at?->toDateTimeString(),
            'delivered_at' => $order->delivered_at?->toDateTimeString(),
            'address_snapshot' => $order->address_snapshot,
            'sub_orders' => collect($order->subOrders)
                ->map(fn (SubOrder $s) => $this->deliverySubOrderPayload($s))
                ->values(),
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }
}
