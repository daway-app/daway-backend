<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PharmacyMedicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $cart = Cart::with('items.pharmacyMedicine.mohMedicine')
            ->where('user_id', $user->id)
            ->first();

        if (! $cart) {
            return response()->json([
                'success' => true,
                'message' => 'سلة التسوق فارغة',
                'data' => [
                    'id' => null,
                    'items' => [],
                    'subtotal' => 0,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب سلة التسوق بنجاح',
            'data' => $this->payload($cart),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $data = $request->validate([
            'pharmacy_medicine_id' => 'required|exists:pharmacy_medicines,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $pharmacyMedicine = PharmacyMedicine::with(['pharmacy', 'mohMedicine', 'medicine'])
            ->findOrFail($data['pharmacy_medicine_id']);

        $pharmacy = $pharmacyMedicine->pharmacy;
        if (! $pharmacy || ! $pharmacy->is_active) {
            throw ValidationException::withMessages([
                'pharmacy_medicine_id' => 'الصيدلية غير نشطة أو غير موجودة',
            ])->status(422);
        }

        if (! $pharmacyMedicine->is_available || $pharmacyMedicine->quantity < $data['quantity']) {
            throw ValidationException::withMessages([
                'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
            ])->status(422);
        }

        $price = (float) $pharmacyMedicine->price;

        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['items_count' => 0, 'subtotal' => 0]
        );

        $existingItem = CartItem::where('cart_id', $cart->id)
            ->where('pharmacy_medicine_id', $data['pharmacy_medicine_id'])
            ->first();

        if ($existingItem) {
            $newQuantity = $existingItem->quantity + $data['quantity'];
            if ($pharmacyMedicine->quantity < $newQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
                ])->status(422);
            }

            $existingItem->update([
                'quantity' => $newQuantity,
                'price' => $price,
            ]);

            $cart->refreshSubtotal();

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الكمية في السلة',
                'data' => $this->itemPayload($existingItem->fresh()),
            ]);
        }

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $pharmacyMedicine->pharmacy_id,
            'pharmacy_medicine_id' => $data['pharmacy_medicine_id'],
            'moh_medicine_id' => $pharmacyMedicine->moh_medicine_id,
            'quantity' => $data['quantity'],
            'price' => $price,
        ]);

        $cart->items_count = $cart->items()->count();
        $cart->refreshSubtotal();

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الدواء إلى السلة',
            'data' => $this->itemPayload($cartItem),
        ], 201);
    }

    public function update(Request $request, CartItem $item): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $item = CartItem::where('cart_id', '=', function ($query) use ($user) {
            $query->select('id')->from('carts')->where('user_id', $user->id);
        })->findOrFail($item->id);

        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $pharmacyMedicine = $item->pharmacyMedicine;

        if (! $pharmacyMedicine || ! $pharmacyMedicine->is_available) {
            throw ValidationException::withMessages([
                'quantity' => 'الدواء غير متوفر حالياً',
            ])->status(422);
        }

        if ($pharmacyMedicine->quantity < $data['quantity']) {
            throw ValidationException::withMessages([
                'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
            ])->status(422);
        }

        $item->update(['quantity' => $data['quantity']]);

        $item->cart->refreshSubtotal();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الكمية',
            'data' => $this->itemPayload($item->fresh()),
        ]);
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $item = CartItem::where('cart_id', '=', function ($query) use ($user) {
            $query->select('id')->from('carts')->where('user_id', $user->id);
        })->findOrFail($item->id);

        $item->delete();

        $item->cart->items_count = $item->cart->items()->count();
        $item->cart->refreshSubtotal();

        return response()->json([
            'success' => true,
            'message' => 'تم إزالة الدواء من السلة',
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $cart = Cart::where('user_id', $user->id)->first();

        if ($cart) {
            $cart->items()->delete();
            $cart->update(['items_count' => 0, 'subtotal' => 0]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تفريغ سلة التسوق',
        ]);
    }

    private function payload(Cart $cart): array
    {
        return [
            'id' => $cart->id,
            'items' => $cart->items->map(fn (CartItem $item) => $this->itemPayload($item))->values(),
            'subtotal' => (float) $cart->subtotal,
            'items_count' => $cart->itemCount(),
        ];
    }

    private function itemPayload(CartItem $item): array
    {
        $pharmacyMedicine = $item->pharmacyMedicine;
        $pharmacy = $pharmacyMedicine?->pharmacy;

        return [
            'id' => $item->id,
            'pharmacy_id' => $pharmacy->id ?? $item->pharmacy_id,
            'pharmacy_name' => $pharmacy?->pharmacy_name,
            'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
            'moh_medicine_id' => $item->moh_medicine_id,
            'medicine' => $pharmacyMedicine?->medicine ? [
                'id' => $pharmacyMedicine->medicine->id,
                'trade_name' => $pharmacyMedicine->medicine->trade_name,
                'active_ingredient' => $pharmacyMedicine->medicine->active_ingredient,
            ] : null,
            'moh_medicine' => $pharmacyMedicine?->mohMedicine ? [
                'id' => $pharmacyMedicine->mohMedicine->id,
                'trade_name' => $pharmacyMedicine->mohMedicine->trade_name,
                'generic_name' => $pharmacyMedicine->mohMedicine->generic_name,
            ] : null,
            'quantity' => $item->quantity,
            'price' => (float) $item->price,
            'total' => (float) $item->total,
        ];
    }
}