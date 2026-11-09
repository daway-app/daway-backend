<?php

namespace App\Services\Checkout;

use App\Exceptions\DeliveryPricingException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Services\DeliveryPricingService;
use App\Services\PharmacyProximityGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حساب ملخص الطلب — مصدر واحد للمعاينة والدفع.
 *
 * سبب الوجود: كان الحساب كله داخل `OrderController::checkout()`، فلا يعرف
 * المريض رسوم التوصيل قبل إنشاء الطلب: السلة تعرض `subtotal` فقط، ثم يظهر
 * `grand_total` مختلف عند الدفع. أي نسخة ثانية من المعادلات ستُعيد نفس
 * الاختلاف، لذلك تُستدعى هذه الخدمة من المسارين.
 *
 * وضعان صريحان:
 *  · `lock: true`  — داخل معاملة الدفع: أقفال صفوف، رفض تغيّر الأسعار.
 *  · `lock: false` — المعاينة: قراءة فقط، بلا أقفال وبلا أي كتابة.
 *
 * الخدمة **لا تكتب شيئًا إطلاقًا** في الوضعين: خصم المخزون، استهلاك الكوبون،
 * وإنشاء الطلب كلها مسؤولية `OrderController`.
 */
class CheckoutQuoteService
{
    public function __construct(private readonly PharmacyProximityGuard $proximity) {}

    /**
     * @return array{
     *     lines: Collection<int, array<string, mixed>>,
     *     subtotal: float,
     *     discount: float,
     *     total: float,
     *     delivery_fee: float,
     *     grand_total: float,
     *     distance_meters: int|null,
     *     pricing_mode: string,
     *     tier_id: int|null,
     *     coupon_code: string|null,
     *     price_changes: list<array<string, mixed>>,
     *     items_count: int
     * }
     *
     * @throws ValidationException
     */
    public function calculate(User $user, Address $address, ?string $couponCode, bool $lock): array
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart || $cart->items()->count() === 0) {
            throw ValidationException::withMessages([
                'cart' => 'سلة التسوق فارغة',
            ]);
        }

        $cartItems = $cart->items;
        $pharmacyMedicineIds = $cartItems->pluck('pharmacy_medicine_id')->toArray();

        // أقفال الصيدليات بنفس الترتيب الثابت الذي يستخدمه الإغلاق، وإلا وقع
        // سباق بين الدفع وإغلاق الحساب. المعاينة لا تقفل شيئًا.
        $pharmacyIds = PharmacyMedicine::query()->whereIn('id', $pharmacyMedicineIds)
            ->distinct()->pluck('pharmacy_id');

        $pharmaciesQuery = Pharmacy::query()->whereIn('id', $pharmacyIds)->orderBy('id');
        $lockedPharmacies = $lock
            ? $pharmaciesQuery->lockForUpdate()->get()->keyBy('id')
            : $pharmaciesQuery->get()->keyBy('id');

        $medicinesQuery = PharmacyMedicine::with(['medicine', 'mohMedicine', 'pharmacy'])
            ->whereIn('id', $pharmacyMedicineIds);

        $pharmacyMedicines = ($lock ? $medicinesQuery->lockForUpdate() : $medicinesQuery)
            ->get()->keyBy('id');

        $this->assertInventory($cartItems, $pharmacyMedicines, $lockedPharmacies);

        [$lines, $subtotal, $priceChanges] = $this->buildLines($cartItems, $pharmacyMedicines);

        // الدفع يرفض عند تغيّر أي سعر؛ المعاينة تُبلغ فقط لأنها بطبيعتها قراءة
        // للحالة الحالية — إخفاء التغيّر عن المريض هو ما نمنعه، لا إظهاره.
        if ($lock && $priceChanges !== []) {
            throw ValidationException::withMessages([
                'price_changed' => 'الأسعار تغيرت على بعض الأدوية. يرجى مراجعة السلة مرة أخرى.',
                'changed_items' => $priceChanges,
            ]);
        }

        $this->assertPharmaciesClose($lines);

        $delivery = $this->deliveryQuote($lines, $address);

        [$discount, $appliedCode] = $this->resolveDiscount($user, $couponCode, $subtotal, $lock);

        // نفس التقييد الموجود في الدفع: الخصم لا يتجاوز مجموع المنتجات.
        $discount = min(max(0.0, $discount), max(0.0, $subtotal));
        $total = max(0.0, $subtotal - $discount);

        // الإجمالي يُحسب من الرسوم **بعد** التقريب، لا قبله.
        $deliveryFee = (float) $delivery['fee'];

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'delivery_fee' => $deliveryFee,
            'grand_total' => round($total + $deliveryFee, 2),
            'distance_meters' => $delivery['distance_meters'],
            'pricing_mode' => $delivery['mode'],
            'tier_id' => $delivery['tier_id'],
            'coupon_code' => $appliedCode,
            'price_changes' => $priceChanges,
            'items_count' => (int) $cartItems->sum('quantity'),
        ];
    }

    /**
     * المخزون + الصيدلية المغلقة. صيدلية مغلقة لا تُسعَّر ولا تُطلب.
     *
     * @param  Collection<int, \App\Models\CartItem>  $cartItems
     * @param  Collection<int, PharmacyMedicine>  $pharmacyMedicines
     * @param  Collection<int, Pharmacy>  $pharmacies
     */
    private function assertInventory(Collection $cartItems, Collection $pharmacyMedicines, Collection $pharmacies): void
    {
        foreach ($cartItems as $item) {
            $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
            $pharmacy = $pm ? $pharmacies->get($pm->pharmacy_id) : null;
            $closed = ! $pharmacy || $pharmacy->closed_at !== null || ! $pharmacy->is_active;

            if (! $pm || $closed || ! $pm->is_available || $pm->quantity < $item->quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
                ]);
            }
        }
    }

    /**
     * بناء السطور بالسعر الرسمي من قاعدة البيانات — لا يُقرأ سعر العميل أبدًا.
     *
     * @param  Collection<int, \App\Models\CartItem>  $cartItems
     * @param  Collection<int, PharmacyMedicine>  $pharmacyMedicines
     * @return array{0: Collection<int, array<string, mixed>>, 1: float, 2: list<array<string, mixed>>}
     */
    private function buildLines(Collection $cartItems, Collection $pharmacyMedicines): array
    {
        $lines = collect();
        $subtotal = 0.0;
        $priceChanges = [];

        foreach ($cartItems as $item) {
            $pm = $pharmacyMedicines->get($item->pharmacy_medicine_id);
            $currentPrice = (float) $pm->price;
            $cartPrice = (float) $item->price;

            if ($currentPrice !== $cartPrice) {
                $priceChanges[] = [
                    'medicine_trade_name' => $pm->medicine->trade_name ?? $pm->mohMedicine->trade_name,
                    'cart_price' => $cartPrice,
                    'current_price' => $currentPrice,
                ];
            }

            $lineTotal = $currentPrice * $item->quantity;
            $subtotal += $lineTotal;

            $lines->push([
                'cart_item' => $item,
                'pharmacy_medicine' => $pm,
                'pharmacy_id' => $pm->pharmacy_id,
                'unit_price' => $currentPrice,
                'line_total' => $lineTotal,
            ]);
        }

        return [$lines, $subtotal, $priceChanges];
    }

    /** @param  Collection<int, array<string, mixed>>  $lines */
    private function assertPharmaciesClose(Collection $lines): void
    {
        $proximity = $this->proximity->inspect(
            $lines->map(fn (array $line) => $line['pharmacy_medicine']->pharmacy)->all()
        );

        if ($proximity !== null) {
            throw ValidationException::withMessages([
                'pharmacy_distance' => [$proximity['message']],
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{fee: float, distance_meters: int|null, tier_id: int|null, mode: string}
     */
    private function deliveryQuote(Collection $lines, Address $address): array
    {
        try {
            return DeliveryPricingService::quoteForCheckout(
                $lines->map(fn (array $line) => $line['pharmacy_medicine']->pharmacy)->filter(),
                $address,
            );
        } catch (DeliveryPricingException $e) {
            // نفس شكل الخطأ في المعاينة والدفع، فلا تختلف الرسالة بينهما.
            throw ValidationException::withMessages([
                'delivery_fee' => [$e->getMessage()],
            ]);
        }
    }

    /** @return array{0: float, 1: string|null} */
    private function resolveDiscount(User $user, ?string $couponCode, float $subtotal, bool $lock): array
    {
        if (empty($couponCode)) {
            return [0.0, null];
        }

        $query = Coupon::where('code', $couponCode);

        // القفل يمنع الاستهلاك المزدوج في الدفع؛ المعاينة تقرأ بلا قفل.
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $coupon || ! $coupon->canApplyToOrder($subtotal)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'الكوبون غير صالح أو لا ينطبق على مجموع الطلب',
            ]);
        }

        if ($coupon->usage_limit > 0 && $coupon->used_count >= $coupon->usage_limit) {
            throw ValidationException::withMessages([
                'coupon_code' => 'تم استنفاد الكوبون',
            ]);
        }

        if ($coupon->per_user_limit > 0) {
            $alreadyUsed = DB::table('coupon_user')
                ->where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'coupon_code' => 'لست مؤهلاً لاستخدام هذا الكوبون',
                ]);
            }
        }

        return [(float) $coupon->calculateDiscount($subtotal), $coupon->code];
    }
}
