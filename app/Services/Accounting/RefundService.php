<?php

namespace App\Services\Accounting;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\PharmacyMedicine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Refund;
use App\Models\RefundItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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

        $alreadyRefunded = Refund::forPharmacy($pharmacyId)
            ->where('sale_id', $sale->id)
            ->sum('amount');

        $availableToRefund = (float) $sale->paid - $alreadyRefunded;

        if ($availableToRefund <= 0) {
            throw new InvalidArgumentException('الفاتورة نفذت إرجاعها بالفعل');
        }

        return DB::transaction(function () use ($pharmacyId, $userId, $sale, $itemsToRefund, $reason, $refundedAt, $availableToRefund) {
            $refundedAt = $refundedAt ?? Carbon::now();
            $totalRefunded = 0.0;
            $refundItems = [];
            $restoredStock = [];
            $toRestoreCash = 0.0;
            $toReduceCustomerBalance = 0.0;

            foreach ($itemsToRefund as $itemData) {
                $itemId = (int) ($itemData['sale_item_id'] ?? 0);
                $quantityToRefund = (int) ($itemData['quantity'] ?? 0);

                if ($quantityToRefund <= 0) {
                    throw new InvalidArgumentException('الكمية يجب أن تكون أكبر من صفر لكل بند');
                }

                $saleItem = SaleItem::query()
                    ->where('id', $itemId)
                    ->where('sale_id', $sale->id)
                    ->first();

                if (!$saleItem) {
                    throw new InvalidArgumentException('بند الفاتورة غير موجود');
                }

                if ($quantityToRefund > $saleItem->quantity) {
                    throw new InvalidArgumentException(
                        "الكمية المطلوب إرجاعها ({$quantityToRefund}) أكبر من المتوفر في الفاتورة ({$saleItem->quantity})"
                    );
                }

                if ($saleItem->pharmacy_medicine_id) {
                    $pm = PharmacyMedicine::query()
                        ->where('id', $saleItem->pharmacy_medicine_id)
                        ->where('pharmacy_id', $pharmacyId)
                        ->first();

                    if (!$pm) {
                        throw new InvalidArgumentException('الصنف غير موجود في مخزون الصيدلية');
                    }
                }
            }

            foreach ($itemsToRefund as $itemData) {
                $itemId = (int) ($itemData['sale_item_id'] ?? 0);
                $quantityToRefund = (int) ($itemData['quantity'] ?? 0);

                $saleItem = SaleItem::query()
                    ->where('id', $itemId)
                    ->where('sale_id', $sale->id)
                    ->firstOrFail();

                $itemRefundAmount = round($saleItem->unit_price * $quantityToRefund -
                    ($saleItem->line_discount * $quantityToRefund / $saleItem->quantity), 2);

                if ($saleItem->line_discount > 0 && $quantityToRefund < $saleItem->quantity) {
                    $itemRefundAmount = round($saleItem->line_total / $saleItem->quantity * $quantityToRefund, 2);
                }

                if ($itemRefundAmount < 0) {
                    $itemRefundAmount = $saleItem->line_total;
                }

                $totalRefunded = round($totalRefunded + $itemRefundAmount, 2);

                if ($saleItem->pharmacy_medicine_id) {
                    $restoredStock[] = [
                        'pharmacy_medicine_id' => $saleItem->pharmacy_medicine_id,
                        'quantity' => $quantityToRefund,
                    ];
                }

                if ($sale->payment_method === Sale::METHOD_CASH && $sale->paid > 0) {
                    $toRestoreCash += $itemRefundAmount;
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

            if ($totalRefunded > $availableToRefund) {
                throw new InvalidArgumentException(
                    "الإجمالي المطلوب إرجاعه ({$totalRefunded}) أكبر من المبلغ المتاح للإرجاع ({$availableToRefund})"
                );
            }

            if ($sale->customer_id && $sale->remaining > 0) {
                $customerRefundShare = $totalRefunded / $sale->total * $sale->remaining;
                $toReduceCustomerBalance = $customerRefundShare;
            }

            $refund = Refund::create([
                'pharmacy_id' => $pharmacyId,
                'sale_id' => $sale->id,
                'created_by' => $userId,
                'refunded_at' => $refundedAt,
                'amount' => $totalRefunded,
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

            foreach ($restoredStock as $stock) {
                PharmacyMedicine::query()
                    ->where('id', $stock['pharmacy_medicine_id'])
                    ->where('pharmacy_id', $pharmacyId)
                    ->increment('quantity', $stock['quantity']);
            }

            if ($sale->payment_method === Sale::METHOD_CASH && $toRestoreCash > 0) {
                AccountingLedger::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_OUT,
                    $toRestoreCash,
                    CashMovement::SOURCE_REFUND,
                    $refund->id,
                    'إرجاع فاتورة '.$sale->number,
                    $refundedAt,
                    $userId,
                    $reason
                );
            }

            if ($sale->customer_id && $toReduceCustomerBalance > 0) {
                $customer = Customer::forPharmacy($pharmacyId)->find($sale->customer_id);
                if ($customer) {
                    AccountingLedger::adjustCustomerBalance($customer, -$toReduceCustomerBalance);
                }
            }

            $newPaid = max(0, (float) $sale->paid - $totalRefunded);
            $newRemaining = round((float) $sale->total - $newPaid, 2);

            $isFullRefund = bccomp((string)$totalRefunded, (string)$sale->total, 2) >= 0;
            $isPartialPaid = $newPaid > 0 && bccomp((string)$newPaid, (string)$sale->total, 2) < 0;

            if ($isFullRefund) {
                $newStatus = Sale::STATUS_REFUNDED;
            } elseif ($isPartialPaid) {
                $newStatus = Sale::STATUS_PARTIALLY_PAID;
            } else {
                $newStatus = $sale->status;
            }

            $sale->update([
                'paid' => $newPaid,
                'remaining' => $newRemaining,
                'status' => $newStatus,
                'notes' => trim(($sale->notes ? $sale->notes . "\n" : '') . 'تم إرجاع: ' . $totalRefunded . ' ₤'),
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

        $item = SaleItem::query()
            ->where('id', $saleItemId)
            ->where('sale_id', $sale->id)
            ->first();

        if (!$item || $quantity > $item->quantity) {
            return false;
        }

        $alreadyRefundedQty = RefundItem::query()
            ->join('refunds', 'refund_items.refund_id', '=', 'refunds.id')
            ->where('refunds.sale_id', $sale->id)
            ->where('refund_items.sale_item_id', $saleItemId)
            ->sum('refund_items.quantity');

        return $quantity <= $item->quantity - $alreadyRefundedQty;
    }
}