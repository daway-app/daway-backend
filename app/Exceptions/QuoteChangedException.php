<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المبلغ الذي رآه المريض لم يعد مطابقًا للمبلغ الفعلي.
 *
 * تُرمى من `OrderController::checkout` قبل أي خصم مخزون أو إنشاء طلب، فتبقى
 * السلة سليمة ويستطيع العميل مراجعة الأرقام الجديدة والموافقة عليها.
 */
class QuoteChangedException extends Exception
{
    /**
     * @param  array<string, array{quoted: float, current: float}>  $changes
     * @param  array<string, mixed>  $quote
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $quote,
    ) {
        parent::__construct('تغيّر المبلغ المعروض. يرجى مراجعة الملخص قبل الدفع.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'تغيّر المبلغ المعروض. يرجى مراجعة الملخص قبل الدفع.',
            'code' => 'quote_changed',
            'data' => [
                'changes' => $this->changes,
                // القيم الحالية كاملة كي تعرض الواجهة الملخص الجديد مباشرة
                // بدل استدعاء معاينة ثانية.
                'quote' => [
                    'subtotal' => $this->quote['subtotal'],
                    'discount' => $this->quote['discount'],
                    'total' => $this->quote['total'],
                    'delivery_fee' => $this->quote['delivery_fee'],
                    'grand_total' => $this->quote['grand_total'],
                    'distance_meters' => $this->quote['distance_meters'],
                    'pricing_mode' => $this->quote['pricing_mode'],
                    'tier_id' => $this->quote['tier_id'],
                ],
            ],
        ], 409);
    }
}
