<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Services\Checkout\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * معاينة ملخص الطلب قبل إنشائه.
 *
 * كل القواعد في `CheckoutQuoteService` — نفس الخدمة التي يستدعيها الدفع.
 * هذا المتحكم يعيد الأرقام فقط، ولا يكتب شيئًا: لا طلب، لا خصم مخزون،
 * لا استهلاك كوبون، لا حجز مبلغ.
 */
class CheckoutQuoteController extends Controller
{
    public function __construct(private readonly CheckoutQuoteService $quotes) {}

    /** POST /api/patient/checkout/quote */
    public function quote(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validate([
            'address_id' => 'required|integer',
            'coupon_code' => 'nullable|string',
        ]);

        // العنوان يجب أن يكون للمستخدم نفسه — لا تسريب أسعار عناوين الآخرين.
        $address = Address::where('id', $data['address_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'العنوان غير صالح',
            ], 422);
        }

        $quote = $this->quotes->calculate(
            $user,
            $address,
            $data['coupon_code'] ?? null,
            lock: false,
        );

        return response()->json([
            'success' => true,
            'message' => 'تم حساب ملخص الطلب',
            'data' => [
                'subtotal' => $quote['subtotal'],
                'discount' => $quote['discount'],
                'total' => $quote['total'],
                // عدد صحيح دائمًا — التقريب يحدث في منطق التسعير المشترك.
                'delivery_fee' => (int) $quote['delivery_fee'],
                'grand_total' => $quote['grand_total'],
                'distance_meters' => $quote['distance_meters'],
                'pricing_mode' => $quote['pricing_mode'],
                'tier_id' => $quote['tier_id'],
                'currency' => 'ILS',
                'items_count' => $quote['items_count'],
                // تُرسل للعميل ليُظهر تنبيهًا إن تغيّر سعر دواء في السلة،
                // ولن تُرفض المعاينة من أجلها (الدفع هو من يرفض).
                'price_changes' => $quote['price_changes'],
            ],
        ]);
    }
}
