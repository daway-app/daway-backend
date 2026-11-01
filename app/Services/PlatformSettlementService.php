<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\PlatformSettlement;
use App\Models\PlatformSettlementAdjustment;
use App\Models\PlatformSettlementItem;
use App\Models\SubOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlatformSettlementService
{
    public function eligibleQuery(int $pharmacyId, string $from, string $to): Builder
    {
        return SubOrder::query()
            ->where('sub_orders.pharmacy_id', $pharmacyId)
            ->where('sub_orders.status', SubOrder::STATUS_DELIVERED)
            ->whereNotNull('sub_orders.delivered_at')
            ->whereBetween(DB::raw('DATE(sub_orders.delivered_at)'), [$from, $to])
            ->whereHas('order', fn (Builder $order) => $order->where('payment_status', Order::PAYMENT_PAID))
            ->whereDoesntHave('settlementItem')
            ->with(['order:id,external_order_id', 'items:id,sub_order_id,medicine_trade_name,quantity,unit_price,total_price'])
            ->orderBy('sub_orders.delivered_at')
            ->orderBy('sub_orders.id');
    }

    public function createDraft(Pharmacy $pharmacy, string $from, string $to, int $adminId): PlatformSettlement
    {
        if ($from > $to) {
            throw new InvalidArgumentException('بداية الفترة يجب أن تسبق نهايتها.');
        }

        return DB::transaction(function () use ($pharmacy, $from, $to, $adminId) {
            $already = PlatformSettlement::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->whereDate('period_start', $from)
                ->whereDate('period_end', $to)
                ->lockForUpdate()
                ->exists();
            if ($already) {
                throw new InvalidArgumentException('توجد دفعة لهذه الصيدلية والفترة مسبقًا.');
            }

            $orders = $this->eligibleQuery($pharmacy->id, $from, $to)->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw new InvalidArgumentException('لا توجد طلبات مؤهلة لهذه الصيدلية ضمن الفترة المحددة.');
            }

            $adjustments = PlatformSettlementAdjustment::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->whereNull('settlement_id')
                ->whereDate('effective_period_start', '<=', $from)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $ordersTotal = round((float) $orders->sum(fn (SubOrder $order) => (float) $order->total), 2);
            $adjustmentsTotal = round((float) $adjustments->sum(fn (PlatformSettlementAdjustment $row) => (float) $row->amount), 2);
            $netTotal = round($ordersTotal + $adjustmentsTotal, 2);
            if ($netTotal < 0) {
                throw new InvalidArgumentException('قيمة التعديلات تتجاوز مستحقات الفترة؛ أضف طلبات مؤهلة قبل إنشاء الدفعة.');
            }

            $settlement = PlatformSettlement::create([
                'pharmacy_id' => $pharmacy->id,
                'pharmacy_name' => $pharmacy->pharmacy_name,
                'period_start' => $from,
                'period_end' => $to,
                'currency' => PlatformSettlement::CURRENCY_ILS,
                'orders_total' => $ordersTotal,
                'adjustments_total' => $adjustmentsTotal,
                'net_total' => $netTotal,
                'status' => PlatformSettlement::STATUS_DRAFT,
                'created_by' => $adminId,
            ]);

            foreach ($orders as $order) {
                $settlement->items()->create([
                    'sub_order_id' => $order->id,
                    'sub_order_id_snapshot' => $order->id,
                    'order_id_snapshot' => $order->order_id,
                    'amount' => $order->total,
                ]);
            }
            if ($adjustments->isNotEmpty()) {
                PlatformSettlementAdjustment::whereKey($adjustments->modelKeys())->update(['settlement_id' => $settlement->id]);
            }

            return $settlement->load(['items.subOrder.items', 'adjustments']);
        });
    }

    public function addAdjustment(Pharmacy $pharmacy, string $effectiveFrom, float $amount, string $reason, int $adminId): PlatformSettlementAdjustment
    {
        $amount = round($amount, 2);
        if ($amount === 0.0) {
            throw new InvalidArgumentException('يجب أن يكون مبلغ التعديل أكبر أو أقل من صفر.');
        }

        return PlatformSettlementAdjustment::create([
            'pharmacy_id' => $pharmacy->id,
            'pharmacy_name' => $pharmacy->pharmacy_name,
            'effective_period_start' => $effectiveFrom,
            'amount' => $amount,
            'reason' => trim($reason),
            'created_by' => $adminId,
        ]);
    }

    public function markPaid(PlatformSettlement $settlement, string $reference, Carbon $paidAt, int $adminId): PlatformSettlement
    {
        return DB::transaction(function () use ($settlement, $reference, $paidAt, $adminId) {
            $locked = PlatformSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== PlatformSettlement::STATUS_DRAFT) {
                throw new InvalidArgumentException('هذه الدفعة مسجّلة كمحوّلة مسبقًا.');
            }
            if ((float) $locked->net_total <= 0) {
                throw new InvalidArgumentException('لا يمكن تسجيل تحويل بقيمة صفر أو أقل.');
            }
            $locked->update([
                'status' => PlatformSettlement::STATUS_PAID,
                'transfer_reference' => trim($reference),
                'paid_at' => $paidAt,
                'paid_by' => $adminId,
            ]);

            return $locked->load(['items.subOrder.items', 'adjustments']);
        });
    }

    public function serialize(PlatformSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'pharmacy_id' => $settlement->pharmacy_id,
            'pharmacy_name' => $settlement->pharmacy_name,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
            'currency' => $settlement->currency,
            'orders_total' => (float) $settlement->orders_total,
            'adjustments_total' => (float) $settlement->adjustments_total,
            'net_total' => (float) $settlement->net_total,
            'status' => $settlement->status,
            'transfer_reference' => $settlement->transfer_reference,
            'paid_at' => $settlement->paid_at?->toIso8601String(),
            'items' => $settlement->relationLoaded('items') ? $settlement->items->map(fn (PlatformSettlementItem $item) => [
                'sub_order_id' => (int) $item->sub_order_id_snapshot,
                'order_id' => (int) $item->order_id_snapshot,
                'amount' => (float) $item->amount,
                'items' => $item->subOrder?->items?->map(fn ($line) => [
                    'name' => $line->medicine_trade_name,
                    'quantity' => (int) $line->quantity,
                    'total' => (float) $line->total_price,
                ])->values() ?? [],
            ])->values() : [],
            'adjustments' => $settlement->relationLoaded('adjustments') ? $settlement->adjustments->map(fn (PlatformSettlementAdjustment $row) => [
                'id' => $row->id,
                'amount' => (float) $row->amount,
                'reason' => $row->reason,
                'effective_period_start' => $row->effective_period_start?->toDateString(),
            ])->values() : [],
        ];
    }
}
