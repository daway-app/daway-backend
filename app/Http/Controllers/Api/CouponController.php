<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function validateCoupon(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validate([
            'code' => 'required|string|exists:coupons,code',
            'order_total' => 'required|numeric|min:0',
        ]);

        $coupon = Coupon::where('code', $data['code'])->first();

        if (! $coupon) {
            return response()->json([
                'success' => false,
                'message' => 'الكوبون غير موجود',
            ], 404);
        }

        if (! $coupon->canApplyToOrder($data['order_total'])) {
            if ($data['order_total'] < $coupon->minimum_order_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "الحد الأدنى للطلب هو {$coupon->minimum_order_amount} ريال",
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'الكوبون غير صالح أو منتهي الصلاحية',
            ], 422);
        }

        $discount = $coupon->calculateDiscount($data['order_total']);

        return response()->json([
            'success' => true,
            'message' => 'الكوبون صالح',
            'data' => [
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'discount' => $discount,
                'minimum_order_amount' => (float) $coupon->minimum_order_amount,
                'maximum_discount' => $coupon->maximum_discount !== null ? (float) $coupon->maximum_discount : null,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $coupons = Coupon::where('is_active', true)->get()->filter(fn ($c) => $c->isValid());

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الكوبونات بنجاح',
            'data' => $coupons->values()->map(fn (Coupon $c) => $this->payload($c)),
        ]);
    }

    private function payload(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'minimum_order_amount' => (float) $coupon->minimum_order_amount,
            'maximum_discount' => $coupon->maximum_discount !== null ? (float) $coupon->maximum_discount : null,
            'expires_at' => $coupon->expires_at?->toDateTimeString(),
            'is_active' => (bool) $coupon->is_active,
        ];
    }
}