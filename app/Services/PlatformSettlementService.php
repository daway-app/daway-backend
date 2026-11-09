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

    /** Delivered and customer-paid orders that have not been included in any payout. */
    public function outstandingQuery(int $pharmacyId): Builder
    {
        return SubOrder::query()
            ->where('sub_orders.pharmacy_id', $pharmacyId)
            ->where('sub_orders.status', SubOrder::STATUS_DELIVERED)
            ->whereNotNull('sub_orders.delivered_at')
            ->whereHas('order', fn (Builder $order) => $order->where('payment_status', Order::PAYMENT_PAID))
            ->whereDoesntHave('settlementItem')
            ->with(['order:id,external_order_id', 'items:id,sub_order_id,medicine_trade_name,quantity,unit_price,total_price'])
            ->orderBy('sub_orders.delivered_at')
            ->orderBy('sub_orders.id');
    }

    /** A single pharmacy balance for the simple admin and pharmacy views. */
    public function outstandingSummary(int $pharmacyId): array
    {
        $orders = $this->outstandingQuery($pharmacyId)->get(['id', 'total']);
        $drafts = PlatformSettlement::query()
            ->where('pharmacy_id', $pharmacyId)
            ->where('status', PlatformSettlement::STATUS_DRAFT)
            ->get(['id', 'net_total']);
        $adjustments = PlatformSettlementAdjustment::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereNull('settlement_id')
            ->whereDate('effective_period_start', '<=', now()->toDateString())
            ->get(['amount']);

        $ordersTotal = round((float) $orders->sum(fn (SubOrder $order) => (float) $order->total), 2);
        $draftsTotal = round((float) $drafts->sum(fn (PlatformSettlement $row) => (float) $row->net_total), 2);
        $adjustmentsTotal = round((float) $adjustments->sum(fn (PlatformSettlementAdjustment $row) => (float) $row->amount), 2);

        return [
            'orders_count' => $orders->count(),
            'orders_total' => $ordersTotal,
            'draft_count' => $drafts->count(),
            'drafts_total' => $draftsTotal,
            'adjustments_total' => $adjustmentsTotal,
            'total_due' => round($ordersTotal + $draftsTotal + $adjustmentsTotal, 2),
        ];
    }

    /**
     * يدمج التعديلات غير المجمّعة في أقدم مسودة قائمة.
     *
     * سبب الوجود: `createDraftFromOutstanding` يدمج التعديلات في مسودة **جديدة**،
     * لكن التعديل الذي يُسجَّل **بعد** إنشاء مسودة كان يبقى معلّقًا للأبد. النتيجة
     * أن شاشة الأدمن تعرض (مسودة 100 + تعديل −20 = 80) بينما التحويل الفعلي
     * يدفع 100، فيختلف المعروض عن المحوّل والمسجّل، ويبقى −20 معلّقًا يقلّب
     * الرصيد إلى السالب.
     *
     * السياسة المالية نفسها محفوظة: التعديل يُطوى في التسوية كما يفعل
     * `createDraftFromOutstanding` تمامًا، لكن على مسودة قائمة بدل انتظار واحدة.
     */
    public function absorbPendingAdjustments(Pharmacy $pharmacy): void
    {
        $adjustments = PlatformSettlementAdjustment::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->whereNull('settlement_id')
            ->whereDate('effective_period_start', '<=', now()->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($adjustments->isEmpty()) {
            return;
        }

        $draft = PlatformSettlement::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('status', PlatformSettlement::STATUS_DRAFT)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        // لا مسودة قائمة: `createDraftFromOutstanding` سيتولى دمجها في مسودة جديدة.
        if (! $draft) {
            return;
        }

        $adjustmentsTotal = round((float) $draft->adjustments_total + (float) $adjustments->sum('amount'), 2);
        $netTotal = round((float) $draft->orders_total + $adjustmentsTotal, 2);

        if ($netTotal <= 0) {
            throw new InvalidArgumentException(
                'التعديلات المعلّقة تجعل صافي المسودة صفرًا أو سالبًا؛ راجع التعديلات قبل تسجيل التحويل.'
            );
        }

        $draft->update([
            'adjustments_total' => $adjustmentsTotal,
            'net_total' => $netTotal,
        ]);

        PlatformSettlementAdjustment::whereKey($adjustments->modelKeys())
            ->update(['settlement_id' => $draft->id]);
    }

    public function createDraftFromOutstanding(Pharmacy $pharmacy, int $adminId): ?PlatformSettlement
    {
        $orders = $this->outstandingQuery($pharmacy->id)->lockForUpdate()->get();
        $adjustments = PlatformSettlementAdjustment::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->whereNull('settlement_id')
            ->whereDate('effective_period_start', '<=', now()->toDateString())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($orders->isEmpty() && $adjustments->isEmpty()) return null;

        $periodDates = $orders->map(fn (SubOrder $order) => Carbon::parse($order->delivered_at)->toDateString());
        $from = $periodDates->min() ?? now()->toDateString();
        $to = $periodDates->max() ?? now()->toDateString();
        $ordersTotal = round((float) $orders->sum(fn (SubOrder $order) => (float) $order->total), 2);
        $adjustmentsTotal = round((float) $adjustments->sum(fn (PlatformSettlementAdjustment $row) => (float) $row->amount), 2);
        $netTotal = round($ordersTotal + $adjustmentsTotal, 2);
        if ($netTotal <= 0) throw new InvalidArgumentException('لا يوجد مبلغ موجب مستحق للتحويل لهذه الصيدلية.');

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

        return DB::transaction(function () use ($pharmacy, $effectiveFrom, $amount, $reason, $adminId) {
            // Closure takes the same row lock; re-read after waiting for it.
            $lockedPharmacy = Pharmacy::query()->whereKey($pharmacy->id)->lockForUpdate()->firstOrFail();
            if ($lockedPharmacy->closed_at !== null) {
                throw new InvalidArgumentException('لا يمكن إضافة تعديل تسوية لصيدلية مغلقة.');
            }

            return PlatformSettlementAdjustment::create([
                'pharmacy_id' => $lockedPharmacy->id,
                'pharmacy_name' => $lockedPharmacy->pharmacy_name,
                'effective_period_start' => $effectiveFrom,
                'amount' => $amount,
                'reason' => trim($reason),
                'created_by' => $adminId,
            ]);
        });
    }

    public function markPaid(PlatformSettlement $settlement, ?string $reference, Carbon $paidAt, int $adminId, array $receipt = []): PlatformSettlement
    {
        return DB::transaction(function () use ($settlement, $reference, $paidAt, $adminId, $receipt) {
            $locked = PlatformSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== PlatformSettlement::STATUS_DRAFT) {
                throw new InvalidArgumentException('هذه الدفعة مسجّلة كمحوّلة مسبقًا.');
            }
            if ((float) $locked->net_total <= 0) {
                throw new InvalidArgumentException('لا يمكن تسجيل تحويل بقيمة صفر أو أقل.');
            }

            // حماية على مستوى المجال: لا سجل "مدفوع" بدون ملف إثبات محفوظ فعلاً.
            // فحص الرفع في الكونترولر قد يُتجاوَز من أي مستدعٍ آخر مستقبلًا.
            $receiptPath = $receipt['transfer_receipt_path'] ?? null;
            if (! is_string($receiptPath) || trim($receiptPath) === '') {
                throw new InvalidArgumentException('لا يمكن تسجيل تحويل مدفوع بدون إشعار تحويل محفوظ.');
            }
            $locked->update([
                'status' => PlatformSettlement::STATUS_PAID,
                'transfer_reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                'paid_at' => $paidAt,
                'paid_by' => $adminId,
                ...$receipt,
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
            'receipt_available' => (bool) $settlement->transfer_receipt_path,
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
