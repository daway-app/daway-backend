<?php

namespace App\Services\Accounting;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * إرجاع بيع — إرجاع بضاعة، وردّ مال، وإلغاء دين — في معاملة واحدة.
 * ══════════════════════════════════════════════════════════════════════════
 * يتعامل الإرجاع مع ثلاثة مكوّنات مستقلة يجب ألا تختلط:
 *
 *   1) **إرجاع البضاعة**: إعادة الكميات إلى المخزون. قيمته = `refunds.amount`.
 *   2) **ردّ الأموال**: نقد يخرج من الصندوق (للكاش)، أو رجوع عبر نفس وسيلة
 *      الدفع (للبطاقة/التحويل). قيمته = `refunds.cash_refunded`.
 *   3) **إلغاء الدين**: الجزء غير المدفوع من قيمة البضاعة المرتجعة يُلغى من
 *      دَين العميل والفاتورة بلا أي حركة نقدية. قيمته = `refunds.debt_cancelled`.
 *
 * العلاقة:  amount = cash_refunded + debt_cancelled  (عند حدّ الثقة للقيمة).
 *
 * ── قاعدة التوزيع: «الدَين أولاً» ──────────────────────────────────────
 * قيمة البضاعة المرتجعة تُخصَم من **الدَين القائم** أولاً، والفائض وحده
 * يُردّ نقدًا:
 *
 *     debt_cancelled = min(R, remaining)
 *     cash_refunded  = R − debt_cancelled
 *
 * هكذا: بضاعة غير مدفوعة تُلغِي دَينًا (لا نطبع نقدًا لم يُحصَّل)، وبضاعة
 * مدفوعة تُرجع نقدًا في حدود ما حُصِّل فعلاً (`cash_refunded ≤ paid`).
 *
 * ── الميزانية على الفاتورة ─────────────────────────────────────────────
 *     net_total = total − refunded_amount
 *     paid      = paid − cash_refunded        (صافي النقد المحتفظ به)
 *     remaining = net_total − paid            (= remaining − debt_cancelled)
 *
 * كلها تُحسب من **سجلات قاعدة البيانات** بعد القفل، لا من قيم يرسلها العميل.
 */
final class RefundService
{
    public static function processRefund(
        int $pharmacyId,
        int $userId,
        Sale $sale,
        array $itemsToRefund,
        ?string $reason = null,
        ?Carbon $refundedAt = null
    ): Refund {
        if ($sale->pharmacy_id !== $pharmacyId) {
            throw new InvalidArgumentException('الفاتورة غير مخصصة لهذه الصيدلية');
        }

        if ($sale->status === Sale::STATUS_CANCELLED || $sale->status === Sale::STATUS_REFUNDED) {
            throw new InvalidArgumentException('لا يمكن إرجاع فاتورة ملغاة أو مرجوعة');
        }

        return DB::transaction(function () use ($pharmacyId, $userId, $sale, $itemsToRefund, $reason, $refundedAt) {
            // ── 0) قفل الصيدلية: يُسلسل كل عمليات الإرجاع النقدي على نفس
            // الدرج، فلا يتنافس طلبان على رصيد واحد (انظر فحص الصندوق أدناه).
            Pharmacy::query()->whereKey($pharmacyId)->lockForUpdate()->first();

            // ── 1) قفل الفاتورة وإعادة قراءة أرصدتها **داخل** المعاملة.
            // طلبان متزامنان كانا يقرآن نفس `paid` قبل الإرجاع فيُرجعان مثليه.
            $sale = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($sale->status === Sale::STATUS_CANCELLED || $sale->status === Sale::STATUS_REFUNDED) {
                throw new InvalidArgumentException('لا يمكن إرجاع فاتورة ملغاة أو مرجوعة');
            }

            $availableToRefund = (float) $sale->paid;
            if ($availableToRefund <= 0 && (float) $sale->remaining <= 0) {
                // لا نقد يُردّ ولا دَين يُلغى ⇒ لا شيء للإرجاع.
                throw new InvalidArgumentException('الفاتورة نفذت إرجاعها بالفعل');
            }

            // ── 2) سقف الكميات التراكمي من سجل الإرجاعات نفسه (لا من العميل).
            $alreadyRefundedQty = RefundItem::query()
                ->join('refunds', 'refund_items.refund_id', '=', 'refunds.id')
                ->where('refunds.sale_id', $sale->id)
                ->select('refund_items.sale_item_id', DB::raw('SUM(refund_items.quantity) as refunded_qty'))
                ->groupBy('refund_items.sale_item_id')
                ->pluck('refunded_qty', 'refund_items.sale_item_id');

            // دمج الأسطر المكرّرة لنفس البند في الطلب الواحد (حتى لا يُتجاوز
            // السقف بإرسال البند مرتين).
            $requested = [];
            foreach ($itemsToRefund as $itemData) {
                $itemId = (int) ($itemData['sale_item_id'] ?? 0);
                $qty = (int) ($itemData['quantity'] ?? 0);

                if ($qty <= 0) {
                    throw new InvalidArgumentException('الكمية يجب أن تكون أكبر من صفر لكل بند');
                }

                $requested[$itemId] = ($requested[$itemId] ?? 0) + $qty;
            }

            if ($requested === []) {
                throw new InvalidArgumentException('يجب تحديد بند واحد على الأقل للإرجاع');
            }

            $refundedAt = $refundedAt ?? Carbon::now();
            $totalRefunded = 0.0;          // R — قيمة البضاعة المرتجعة (amount)
            $cashRefunded = 0.0;           // المال المُردّ فعليًا
            $refundItems = [];
            $restoredStock = [];

            foreach ($requested as $itemId => $quantityToRefund) {
                $saleItem = SaleItem::query()
                    ->where('id', $itemId)
                    ->where('sale_id', $sale->id)
                    ->first();

                if (! $saleItem) {
                    throw new InvalidArgumentException('بند الفاتورة غير موجود');
                }

                $alreadyQty = (int) ($alreadyRefundedQty[$itemId] ?? 0);
                $maxQty = (int) $saleItem->quantity - $alreadyQty;

                if ($quantityToRefund > $maxQty) {
                    throw new InvalidArgumentException(
                        "الكمية المطلوب إرجاعها ({$quantityToRefund}) أكبر من المتاح للإرجاع ({$maxQty}) لبند: {$saleItem->medicine_name}"
                    );
                }

                if ($saleItem->pharmacy_medicine_id) {
                    $pm = PharmacyMedicine::query()
                        ->where('id', $saleItem->pharmacy_medicine_id)
                        ->where('pharmacy_id', $pharmacyId)
                        ->first();

                    if (! $pm) {
                        throw new InvalidArgumentException('الصنف غير موجود في مخزون الصيدلية');
                    }
                }

                // قيمة الوحدة الصافية = إجمالي السطر ÷ كميته (يقسّم الخصم بعدل).
                // الإرجاع الجزئي يأخذ نصيبه، والإرجاع الكامل يعيد إجمالي السطر.
                $lineTotal = (float) $saleItem->line_total;
                $lineQty = max(1, (int) $saleItem->quantity);
                $itemRefundAmount = round($lineTotal / $lineQty * $quantityToRefund, 2);

                if ($itemRefundAmount < 0) {
                    $itemRefundAmount = $lineTotal;
                }

                $totalRefunded = round($totalRefunded + $itemRefundAmount, 2);

                if ($saleItem->pharmacy_medicine_id) {
                    $restoredStock[] = [
                        'pharmacy_medicine_id' => $saleItem->pharmacy_medicine_id,
                        'quantity' => $quantityToRefund,
                    ];
                }

                $refundItems[] = [
                    'sale_item_id' => $itemId,
                    'quantity' => $quantityToRefund,
                    'amount' => $itemRefundAmount,
                ];
            }

            if ($totalRefunded <= 0) {
                throw new InvalidArgumentException('مجموع الإرجاع يجب أن يكون أكبر من صفر');
            }

            // ── 3) توزيع قيمة البضاعة المرتجعة: دَين أولاً ثم نقد.
            $remaining = round((float) $sale->remaining, 2);
            $paid = round((float) $sale->paid, 2);

            $debtCancelled = round(min($totalRefunded, $remaining), 2);
            $cashRefunded = round($totalRefunded - $debtCancelled, 2);

            // لا يُردّ نقد أكثر مما حُصِّل فعلاً — ضمانة صريحة لا تعتمد على
            // صحة القاعدة وحدها (وإن كانت النتيجة الحسابية تضمنها).
            if (bccomp((string) $cashRefunded, (string) $paid, 2) > 0) {
                throw new InvalidArgumentException('المبلغ المطلوب ردّه أكبر من المبلغ المحصَّل على الفاتورة');
            }

            // ── 4) حماية الصندوق (كاش فقط): لا يُسجَّل خروج نقدي يتجاوز الرصيد.
            $isCash = AccountingLedger::isCashTender((string) $sale->payment_method);

            if ($cashRefunded > 0 && $isCash) {
                $drawer = AccountingReports::cashBalance($pharmacyId);

                if (bccomp((string) $cashRefunded, (string) $drawer, 2) > 0) {
                    throw new InvalidArgumentException(
                        'رصيد الصندوق غير كافٍ: المتاح '.number_format($drawer, 2).
                        ' والمطلوب ردّه '.number_format($cashRefunded, 2)
                    );
                }
            }

            // ── 5) سجل الإرجاع وبنوده (المصدر التدقيقي).
            $refund = Refund::create([
                'pharmacy_id' => $pharmacyId,
                'sale_id' => $sale->id,
                'created_by' => $userId,
                'refunded_at' => $refundedAt,
                'amount' => $totalRefunded,
                'cash_refunded' => $cashRefunded,
                'debt_cancelled' => $debtCancelled,
                'reason' => $reason,
                'status' => Refund::STATUS_COMPLETED,
            ]);

            foreach ($refundItems as $refundItem) {
                RefundItem::create([
                    'refund_id' => $refund->id,
                    'sale_item_id' => $refundItem['sale_item_id'],
                    'quantity' => $refundItem['quantity'],
                    'amount' => $refundItem['amount'],
                ]);
            }

            // ── 6) إعادة البضاعة إلى المخزون (بمُعرّف يخصّ هذه الصيدلية).
            foreach ($restoredStock as $stock) {
                PharmacyMedicine::query()
                    ->where('id', $stock['pharmacy_medicine_id'])
                    ->where('pharmacy_id', $pharmacyId)
                    ->increment('quantity', $stock['quantity']);
            }

            // ── 7) ردّ المال: حركة صندوق **فقط** للكاش. البطاقة/التحويل
            // تخرج عبر وسيلتها (بلا حركة درج)، فلا تُنفَخ النقدية كذبًا.
            if ($cashRefunded > 0 && $isCash) {
                AccountingLedger::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_OUT,
                    $cashRefunded,
                    CashMovement::SOURCE_REFUND,
                    $refund->id,
                    'إرجاع فاتورة '.$sale->number,
                    $refundedAt,
                    $userId,
                    $reason
                );
            }

            // ── 8) إلغاء الدين: تعديل **واحد** لرصيد العميل بمقدار الجزء
            // غير المدفوع فقط — لا تعديل ثانٍ بالمبلغ النقدي (الذي لولا ذلك
            // لطُرح مرّتين: مرة كدَين ومرة كردّ مال).
            if ($debtCancelled > 0 && $sale->customer_id) {
                $customer = Customer::forPharmacy($pharmacyId)->find($sale->customer_id);
                if ($customer) {
                    AccountingLedger::adjustCustomerBalance($customer, -1 * $debtCancelled);
                }
            }

            // ── 9) تحديث الفاتورة على الميزانية الجديدة.
            $newRefunded = round((float) $sale->refunded_amount + $totalRefunded, 2);
            $newPaid = round($paid - $cashRefunded, 2);
            $newNetTotal = round((float) $sale->total - $newRefunded, 2);
            $newRemaining = round($newNetTotal - $newPaid, 2);

            $sale->update([
                'refunded_amount' => $newRefunded,
                'paid' => $newPaid,
                'remaining' => $newRemaining,
                'status' => Sale::deriveRefundAwareStatus(
                    (float) $sale->total,
                    $newRefunded,
                    $newNetTotal,
                    $newPaid
                ),
                'notes' => trim(($sale->notes ? $sale->notes."\n" : '').'تم إرجاع: '.$totalRefunded.' ₪'),
            ]);

            return $refund->load('items.saleItem');
        });
    }

    public static function canRefundItem(Sale $sale, int $pharmacyId, int $saleItemId, int $quantity): bool
    {
        if ($sale->pharmacy_id !== $pharmacyId) {
            return false;
        }

        if ($sale->status === Sale::STATUS_CANCELLED || $sale->status === Sale::STATUS_REFUNDED) {
            return false;
        }

        if ($quantity <= 0) {
            return false;
        }

        $item = SaleItem::query()
            ->where('id', $saleItemId)
            ->where('sale_id', $sale->id)
            ->first();

        if (! $item) {
            return false;
        }

        $alreadyRefundedQty = (int) RefundItem::query()
            ->join('refunds', 'refund_items.refund_id', '=', 'refunds.id')
            ->where('refunds.sale_id', $sale->id)
            ->where('refund_items.sale_item_id', $saleItemId)
            ->sum('refund_items.quantity');

        return $quantity <= ((int) $item->quantity - $alreadyRefundedQty);
    }
}
