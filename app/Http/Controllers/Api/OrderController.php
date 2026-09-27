<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PharmacyMedicine;
use App\Models\Pharmacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $orders = Order::where('user_id', $user->id)
            ->with(['pharmacy', 'items.pharmacyMedicine'])
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات بنجاح',
            'data' => $this->payloadCollection($orders->items()),
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        if ($order->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        $order->load(['pharmacy', 'items.pharmacyMedicine', 'items.pharmacy', 'address']);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الطلب بنجاح',
            'data' => $this->payload($order),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'coupon_code' => 'nullable|string|exists:coupons,code',
        ]);

        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart || $cart->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'سلة التسويع فارغة',
            ], 422);
        }

        $address = Address::where('id', $data['address_id'])->where('user_id', $user->id)->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'العنوان غير صالح',
            ], 422);
        }

        return DB::transaction(function () use ($user, $cart, $address, $data) {
            $cartItems = $cart->items;

            $pharmacyMedicineIds = $cartItems->pluck('pharmacy_medicine_id')->toArray();

            $pharmacyMedicines = PharmacyMedicine::with(['medicine', 'mohMedicine', 'pharmacy'])
                ->whereIn('id', $pharmacyMedicineIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $inventoryValid = true;
            foreach ($cartItems as $item) {
                $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
                if (! $pm || ! $pm->is_available || $pm->quantity < $item->quantity) {
                    $inventoryValid = false;
                    break;
                }
            }

            if (! $inventoryValid) {
                throw ValidationException::withMessages([
                    'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
                ]);
            }

            $subtotal = 0;
            foreach ($cartItems as $item) {
                $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
                $price = (float) $item->price;
                $subtotal += $price * $item->quantity;
            }

            $discount = 0;
            $couponCode = null;

            if (! empty($data['coupon_code'])) {
                $coupon = \App\Models\Coupon::where('code', $data['coupon_code'])->first();

                if (! $coupon || ! $coupon->canApplyToOrder($subtotal)) {
                    throw ValidationException::withMessages([
                        'coupon_code' => 'الكوبون غير صالح أو لا ينطبق على مجموع الطلب',
                    ]);
                }

                $discount = $coupon->calculateDiscount($subtotal);
                $couponCode = $coupon->code;
            }

            $total = $subtotal - $discount;

            foreach ($cartItems as $item) {
                $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
                PharmacyMedicine::where('id', $item->pharmacy_medicine_id)
                    ->decrement('quantity', $item->quantity);
            }

            $priceSnapshot = [];
            foreach ($cartItems as $item) {
                $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
                $priceSnapshot[] = [
                    'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
                    'medicine_trade_name' => $pm->medicine->trade_name ?? $pm->mohMedicine->trade_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->price,
                    'total_price' => (float) ($item->price * $item->quantity),
                ];
            }

            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                'pharmacy_id' => $cartItems->first()?->pharmacy_id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'coupon_code' => $couponCode,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_PENDING,
                'snapshot' => $priceSnapshot,
            ]);

            foreach ($cartItems as $item) {
                $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
                $order->items()->create([
                    'pharmacy_id' => $item->pharmacy_id,
                    'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
                    'moh_medicine_id' => $pm->moh_medicine_id,
                    'medicine_trade_name' => $pm->medicine->trade_name ?? $pm->mohMedicine->trade_name,
                    'medicine_description' => $pm->medicine->description ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->price,
                    'total_price' => (float) ($item->price * $item->quantity),
                    'availability_status' => $pm->is_available ? 'available' : 'unavailable',
                ]);
            }

            $order->addStatus(Order::STATUS_CONFIRMED, 'تم تأكيد الطلب');

            $cart->items()->delete();

            return response()->json([
                'success' => true,
                'message' => 'تم إنشاء الطلب بنجاح',
                'data' => [
                    'order_id' => $order->id,
                    'subtotal' => (float) $order->subtotal,
                    'discount' => (float) $order->discount,
                    'total' => (float) $order->total,
                    'status' => $order->status,
                ],
            ], 201);
        }, 5, 3);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        if ($order->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        if ($order->isExpired()) {
            return response()->json(['success' => false, 'message' => 'لا يمكن إلغاء طلب مكتمل'], 422);
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);
        $order->addStatus(Order::STATUS_CANCELLED, 'تم إلغاء الطلب');

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الطلب بنجاح',
        ]);
    }

    public function tracking(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        if ($order->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        $order->load(['pharmacy', 'items.pharmacyMedicine', 'items.pharmacy', 'address', 'statusHistories']);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تتبع الطلب بنجاح',
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'status_histories' => $order->statusHistories->map(fn ($history) => [
                    'status' => $history->status,
                    'note' => $history->note,
                    'created_at' => $history->created_at?->toDateTimeString(),
                    'status_changed_at' => $history->status_changed_at?->toDateTimeString(),
                ]),
                'current_status' => $order->status,
                'pharmacy' => $order->pharmacy ? [
                    'id' => $order->pharmacy->id,
                    'name' => $order->pharmacy->pharmacy_name,
                ] : null,
                'delivered_at' => $order->delivered_at?->toDateTimeString(),
                'ready_at' => $order->ready_at?->toDateTimeString(),
            ],
        ]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $pharmacy = $request->user();

        abort_unless($pharmacy->role === 'pharmacy', 403);

        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                Order::STATUS_PREPARING,
                Order::STATUS_READY,
                Order::STATUS_OUT_FOR_DELIVERY,
                Order::STATUS_DELIVERED,
                Order::STATUS_CANCELLED,
            ]),
            'note' => 'nullable|string|max:255',
        ]);

        $pharmacyId = $pharmacy->pharmacy?->id;

        $hasItemsFromPharmacy = $order->items()
            ->where('pharmacy_id', $pharmacyId)
            ->exists();

        if (! $hasItemsFromPharmacy) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تحديث حالة طلب لا يخص هذه الصيدلية',
            ], 403);
        }

        if (! $order->canTransitionTo($data['status'])) {
            $allowed = [
                Order::STATUS_PENDING => 'confirmed',
                Order::STATUS_CONFIRMED => 'preparing, cancelled',
                Order::STATUS_PREPARING => 'ready, cancelled',
                Order::STATUS_READY => 'out_for_delivery, cancelled',
                Order::STATUS_OUT_FOR_DELIVERY => 'delivered, cancelled',
                Order::STATUS_DELIVERED => 'none',
                Order::STATUS_CANCELLED => 'none',
            ];

            $allowedList = $allowed[$order->status] ?? 'none';
            return response()->json([
                'success' => false,
                'message' => "لا يمكن الانتقال من حالة {$order->status} إلى {$data['status']}. الحالات المسموحة: {$allowedList}",
            ], 422);
        }

        if ($data['status'] === Order::STATUS_DELIVERED) {
            $order->update(['delivered_at' => now()]);
        }

        $order->addStatus($data['status'], $data['note'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
            ],
        ]);
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'coupon_code' => $order->coupon_code,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'items_count' => $order->items->count(),
            'address' => $order->address ? [
                'id' => $order->address->id,
                'label' => $order->address->label,
                'recipient_name' => $order->address->recipient_name,
                'address' => $order->address->address,
            ] : null,
            'pharmacy' => $order->pharmacy ? [
                'id' => $order->pharmacy->id,
                'name' => $order->pharmacy->pharmacy_name,
            ] : null,
            'items' => collect($order->items)->map(fn ($item) => [
                'id' => $item->id,
                'pharmacy_id' => $item->pharmacy_id,
                'pharmacy_name' => $item->pharmacy?->pharmacy_name,
                'medicine_trade_name' => $item->medicine_trade_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ])->values(),
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }

    private function payloadCollection($orders): array
    {
        return collect($orders)->map(fn ($order) => $this->payload($order))->values()->all();
    }
}