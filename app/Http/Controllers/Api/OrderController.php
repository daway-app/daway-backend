<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CheckoutIdempotency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PharmacyMedicine;
use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Exceptions\DeliveryPricingException;
use App\Services\DeliveryPricingService;
use App\Services\OrderCancellationPolicy;
use App\Services\OrderNotificationService;
use App\Services\PaymentFulfillmentGate;
use App\Services\PharmacyProximityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderNotificationService $notifications,
        private readonly PaymentFulfillmentGate $paymentGate,
        private readonly OrderCancellationPolicy $cancelPolicy
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        $orders = Order::where('user_id', $user->id)
            ->with(['pharmacy', 'items.pharmacyMedicine', 'subOrders.pharmacy'])
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

        $order->load(['pharmacy', 'items.pharmacyMedicine', 'items.pharmacy', 'subOrders.pharmacy', 'address']);

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
            'idempotency_key' => 'nullable|string|max:255',
        ]);

        // Phase 0 — idempotency: a replay with the SAME key returns the original
        // order instead of creating a new one. Keys are user-scoped and stored
        // in the DB (checkout_idempotencies) so concurrent replays are safe.
        // NOTE: this lookup MUST come before the cart check — a replay arrives
        // with an already-emptied cart and must still resolve to its order.
        $idempotencyKeyHash = null;
        if (! empty($data['idempotency_key'])) {
            $idempotencyKeyHash = sha1($data['idempotency_key']);
            $existingId = CheckoutIdempotency::where('user_id', $user->id)
                ->where('key_hash', $idempotencyKeyHash)
                ->value('order_id');

            if ($existingId) {
                $existing = Order::with(['pharmacy', 'items.pharmacyMedicine', 'subOrders'])
                    ->find($existingId);
                if ($existing) {
                    return response()->json([
                        'success' => true,
                        'message' => 'تم إنشاء الطلب بنجاح',
                        'data' => [
                            'order_id' => $existing->id,
                            'subtotal' => (float) $existing->subtotal,
                            'discount' => (float) $existing->discount,
                            'total' => (float) $existing->total,
                            'delivery_fee' => (float) $existing->delivery_fee,
                            'grand_total' => round((float) $existing->total + (float) $existing->delivery_fee, 2),
                            'status' => $existing->status,
                            'sub_orders_count' => $existing->subOrders->count(),
                            'idempotent' => true,
                        ],
                    ], 201, [], JSON_UNESCAPED_UNICODE);
                }
            }
        }

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

        $order = DB::transaction(function () use ($user, $cart, $address, $data, $idempotencyKeyHash) {
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
                // صيدلية مغلقة لا تستقبل طلبات جديدة: نمنعها هنا لا في الواجهة
                // فقط، لأن البحث قد يكون مخزّنًا أو السلة قديمة.
                $pharmacyClosed = $pm?->pharmacy
                    && ($pm->pharmacy->closed_at !== null || $pm->pharmacy->is_active === false);

                if (! $pm || $pharmacyClosed || ! $pm->is_available || $pm->quantity < $item->quantity) {
                    $inventoryValid = false;
                    break;
                }
            }

            if (! $inventoryValid) {
                throw ValidationException::withMessages([
                    'quantity' => 'الكمية المطلوبة غير متوفرة في المخزون',
                ]);
            }

            // Phase 0 — server-side price re-verification.
            // The OFFICIAL price source is pharmacy_medicines.price (locked above).
            // We NEVER trust the client-sent $item->price. If the price changed
            // since the cart was last refreshed, we reject without silent update.
            //
            // Phase 1 — we also build the per-pharmacy "lines" here: each line is
            // resolved against its locked PharmacyMedicine row, and its pharmacy
            // is taken from that row (authoritative), not from the cart copy.
            $priceChanges = [];
            $subtotal = 0;
            $lines = collect();

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

                // Always recompute with the authoritative server price.
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

            if (! empty($priceChanges)) {
                // Reject + let the client review the cart. Stock is NOT decremented.
                throw ValidationException::withMessages([
                    'price_changed' => 'الأسعار تغيرت على بعض الأدوية. يرجى مراجعة السلة مرة أخرى.',
                    'changed_items' => $priceChanges,
                ]);
            }

            // ── Phase 2 — تقارب صيدليات الطلب ─────────────────────────────
            // الطلب الواحد يُخدم برحلة توصيل واحدة، فأبعد زوج من صيدلياته
            // يجب ألا يتجاوز 500 متر. الموضع مقصود: بعد بناء `$lines` من
            // صفوف المخزون المقفلة (فالصيدلية مصدرها موثوق لا نسخة السلة)،
            // وقبل أي خصم مخزون أو إنشاء طلب. الرفض هنا يتراجع بالكامل
            // لأننا داخل الـtransaction — لا طلب ولا بند ولا خصم جزئي.
            //
            // `pharmacy` محمّلة مسبقاً في استعلام `lockForUpdate` أعلاه،
            // و`pharmacy_medicines.pharmacy_id` هو `cascadeOnDelete` فلا
            // يمكن أن تكون العلاقة null لسطر سلة حقيقي.
            $proximity = PharmacyProximityGuard::inspect(
                $lines->map(fn (array $line) => $line['pharmacy_medicine']->pharmacy)->all()
            );

            if ($proximity !== null) {
                throw ValidationException::withMessages([
                    'pharmacy_distance' => [$proximity['message']],
                ]);
            }

            // ── Phase 1 — تسعير التوصيل ───────────────────────────────────
            // يُحسب هنا **قبل** أي خصم مخزون أو إنشاء طلب (بنفس موضع بوابة
            // التقارب أعلاه) حتى يتراجع الرفض بالكامل: لا طلب ولا بند ولا خصم
            // جزئي. الرسوم تُحتسب في الخادم فقط، وقيمة العميل لا تُقرأ أبدًا.
            // عند تعطيل «تسعير التوصيل» أو غياب مفتاح التفعيل: يُرفض الطلب
            // (خطأ `delivery_fee`) بلا إنشاء أي طلب — لا رسوم صفر صامتة.
            // متعدد الصيدليات: تُحسب مسافة أقصر مسار مفتوح (TSP) يزور كل صيدليات
            // الطلب وينتهي بالعنوان، ثم تُطبَّق الشرائح على الإجمالي — رسوم واحدة
            // على الطلب، لا مجموع رسوم لكل صيدلية.
            try {
                $deliveryQuote = DeliveryPricingService::quoteForCheckout(
                    $lines->map(fn (array $line) => $line['pharmacy_medicine']->pharmacy)->filter(),
                    $address,
                );
            } catch (DeliveryPricingException $e) {
                throw ValidationException::withMessages([
                    'delivery_fee' => [$e->getMessage()],
                ]);
            }

            $deliveryFee = (float) $deliveryQuote['fee'];

            $discount = 0;
            $couponCode = null;

            if (! empty($data['coupon_code'])) {
                // Phase 0 — enforce coupon usage limits atomically inside the
                // order transaction. Lock the row to prevent double-spend under
                // concurrent checkouts.
                $coupon = \App\Models\Coupon::where('code', $data['coupon_code'])
                    ->lockForUpdate()
                    ->first();

                if (! $coupon || ! $coupon->canApplyToOrder($subtotal)) {
                    throw ValidationException::withMessages([
                        'coupon_code' => 'الكوبون غير صالح أو لا ينطبق على مجموع الطلب',
                    ]);
                }

                // Global usage-limit enforcement (used_count was previously never incremented).
                if ($coupon->usage_limit > 0 && $coupon->used_count >= $coupon->usage_limit) {
                    throw ValidationException::withMessages([
                        'coupon_code' => 'تم استنفاد الكوبون',
                    ]);
                }

                // Per-user usage-limit enforcement via the coupon_user pivot.
                if ($coupon->per_user_limit > 0) {
                    $alreadyUsed = \DB::table('coupon_user')
                        ->where('coupon_id', $coupon->id)
                        ->where('user_id', $user->id)
                        ->exists();
                    if ($alreadyUsed) {
                        throw ValidationException::withMessages([
                            'coupon_code' => 'لست مؤهلاً لاستخدام هذا الكوبون',
                        ]);
                    }
                }

                $discount = $coupon->calculateDiscount($subtotal);
                $couponCode = $coupon->code;
            }

            // Phase 0 — the coupon discount is already capped at the eligible
            // product subtotal by Coupon::calculateDiscount(). Clamp it once more
            // here so the payable total can never go negative, whatever the
            // coupon configuration. The clamped value is the one distributed
            // across the pharmacy sub-orders below, so the parent total and every
            // sub-order total stay consistent.
            $discount = min(max(0.0, (float) $discount), max(0.0, (float) $subtotal));
            $total = max(0.0, $subtotal - $discount);

            foreach ($lines as $line) {
                PharmacyMedicine::where('id', $line['pharmacy_medicine']->id)
                    ->decrement('quantity', $line['cart_item']->quantity);
            }

            $priceSnapshot = $lines->map(function ($line) {
                return [
                    'pharmacy_medicine_id' => $line['pharmacy_medicine']->id,
                    'medicine_trade_name' => $line['pharmacy_medicine']->medicine->trade_name
                        ?? $line['pharmacy_medicine']->mohMedicine->trade_name,
                    'quantity' => $line['cart_item']->quantity,
                    'unit_price' => $line['unit_price'],
                    'total_price' => (float) ($line['unit_price'] * $line['cart_item']->quantity),
                ];
            })->values()->all();

            // Phase 0 — frozen address snapshot at checkout time.
            $addressSnapshot = [
                'recipient_name' => $address->recipient_name,
                'phone' => $address->phone,
                'address' => $address->address,
                'label' => $address->label,
                'latitude' => $address->latitude ? (float) $address->latitude : null,
                'longitude' => $address->longitude ? (float) $address->longitude : null,
                'city' => $address->city ?? null,
            ];

            // Phase 1 — group the lines by pharmacy. Each pharmacy becomes its own
            // sub-order with an independent lifecycle; the parent `orders` row is
            // the header (patient, address snapshot, grand totals).
            $groups = $lines->groupBy('pharmacy_id');

            $order = Order::create([
                'user_id' => $user->id,
                'address_id' => $address->id,
                // Single-pharmacy orders keep `pharmacy_id` for backward
                // compatibility with pre-Phase-1 consumers; multi-pharmacy
                // orders leave it NULL and expose the pharmacies via sub-orders.
                'pharmacy_id' => $groups->count() === 1 ? $groups->keys()->first() : null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                // Phase 1 — لقطة رسوم التوصيل على الأب (قيمة واحدة للرحلة).
                // لا تدخل في `total`؛ الإجمالي النهائي = total + delivery_fee.
                'delivery_fee' => $deliveryFee,
                'coupon_code' => $couponCode,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_PENDING,
                'snapshot' => $priceSnapshot,
                'address_snapshot' => $addressSnapshot,
            ]);

            $groupCount = $groups->count();
            $groupIndex = 0;
            $remainingDiscount = round((float) $discount, 2);

            foreach ($groups as $pharmacyId => $groupLines) {
                $groupIndex++;

                $groupSubtotal = round((float) $groupLines->sum('line_total'), 2);

                // Distribute the coupon discount proportionally to each
                // pharmacy's share; the LAST group absorbs any rounding
                // remainder so the sum of sub-order discounts == order discount.
                if ($groupIndex === $groupCount) {
                    $groupDiscount = $remainingDiscount;
                } else {
                    $groupDiscount = $subtotal > 0
                        ? round((float) $discount * ($groupSubtotal / $subtotal), 2)
                        : 0.0;
                    $remainingDiscount = round($remainingDiscount - $groupDiscount, 2);
                }

                $subOrder = $order->subOrders()->create([
                    'pharmacy_id' => $pharmacyId,
                    'subtotal' => $groupSubtotal,
                    'discount' => $groupDiscount,
                    'shipping_fee' => 0,
                    'commission' => 0,
                    // Guarded so a sub-order total can never be negative: the
                    // discount is capped at the eligible subtotal and allocated
                    // proportionally to each pharmacy's share.
                    'total' => max(0.0, round($groupSubtotal - $groupDiscount, 2)),
                    'status' => SubOrder::STATUS_PENDING,
                    'payment_status' => SubOrder::PAYMENT_PENDING,
                ]);

                foreach ($groupLines as $line) {
                    $pm = $line['pharmacy_medicine'];
                    $unitPrice = $line['unit_price'];

                    $subOrder->items()->create([
                        'order_id' => $order->id,
                        'pharmacy_id' => $line['pharmacy_id'],
                        'pharmacy_medicine_id' => $pm->id,
                        'moh_medicine_id' => $pm->moh_medicine_id,
                        'medicine_trade_name' => $pm->medicine->trade_name ?? $pm->mohMedicine->trade_name,
                        'medicine_description' => $pm->medicine->description ?? null,
                        'quantity' => $line['cart_item']->quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => (float) ($unitPrice * $line['cart_item']->quantity),
                        'availability_status' => $pm->is_available ? 'available' : 'unavailable',
                    ]);
                }

                $subOrder->addStatus(SubOrder::STATUS_CONFIRMED, 'تم تأكيد الطلب');
            }

            $order->addStatus(Order::STATUS_CONFIRMED, 'تم تأكيد الطلب');

            // Phase 0 — commit coupon consumption AFTER order creation succeeds,
            // so a failed order never burns a coupon usage. Done inside the
            // transaction so a coupon increment + stock decrement stay atomic.
            if ($couponCode) {
                $coupon = \App\Models\Coupon::where('code', $couponCode)->first();
                if ($coupon) {
                    $coupon->increment('used_count');

                    if ($coupon->per_user_limit > 0) {
                        \DB::table('coupon_user')->insertOrIgnore([
                            'coupon_id' => $coupon->id,
                            'user_id' => $user->id,
                            'used_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $coupon->increment('users_used_count');
                    }
                }
            }

            // Persist idempotency key result so replays return the same order.
            // The unique (user_id, key_hash) constraint guarantees correctness.
            if ($idempotencyKeyHash) {
                \DB::table('checkout_idempotencies')->insert([
                    'user_id' => $user->id,
                    'key_hash' => $idempotencyKeyHash,
                    'order_id' => $order->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $order;
        }, 5, 3);

        // Phase 0 — run AFTER the order transaction committed.
        $cart->items()->delete();

        $this->notifications->orderCreated($order);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الطلب بنجاح',
            'data' => [
                'order_id' => $order->id,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
                // لقطة الرسوم + الإجمالي النهائي الذي يدفعه المريض.
                'delivery_fee' => (float) $order->delivery_fee,
                'grand_total' => round((float) $order->total + (float) $order->delivery_fee, 2),
                'status' => $order->status,
                'sub_orders_count' => $order->subOrders()->count(),
            ],
        ], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'patient', 403);

        if ($order->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        // Phase 2 — fast-path denial using a non-locked read so the user gets a
        // prompt, consistent rejection; the locked row inside the transaction
        // re-checks the same policy to defeat TOCTOU races.
        $denial = $this->cancelPolicy->denialForCancel($order);

        if ($denial !== null) {
            return response()->json([
                'success' => false,
                'message' => $denial['message'],
                'data' => $denial['data'],
            ], $denial['status']);
        }

        // Phase 1 — patient cancellation is WHOLE-ORDER only (no partial cancel).
        // We cancel every still-active sub-order first, then the parent, so the
        // derived parent status and the sub-order states stay consistent.
        // Phase 0 — stock is given back here, exactly once per cancelled entity.
        $cancelled = DB::transaction(function () use ($order) {
            // Lock the order row first: two concurrent cancel requests must not
            // both pass the check above and restore stock twice.
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return 'not_found';
            }

            $recheck = $this->cancelPolicy->denialForCancel($locked);

            if ($recheck !== null) {
                return $recheck;
            }

            $subOrders = $locked->subOrders()->lockForUpdate()->get();

            if ($subOrders->isEmpty()) {
                // Legacy (pre-Phase-1) order: its lines hang directly off the
                // order, so restore them at the order level only.
                $this->restoreOrderInventory($locked);
            } else {
                foreach ($subOrders as $subOrder) {
                    if ($subOrder->isExpired()) {
                        continue;
                    }

                    // Restore this pharmacy's lines through the sub-order path.
                    $this->restoreSubOrderInventory($subOrder);

                    $subOrder->addStatus(SubOrder::STATUS_CANCELLED, 'تم إلغاء الطلب');
                }

                // A parent order that has sub-orders may still carry LEGACY lines
                // with `sub_order_id = NULL` (the Phase 1.2 remediation downgrades
                // unresolvable links to legacy rows). Those belong to the parent, so
                // they are restored here. The helper filters `whereNull(...)`, so no
                // sub-order line can be restocked a second time.
                $this->restoreOrderInventory($locked);
            }

            // Phase 0 (audit trail) — a single write is enough. `addStatus()`
            // already persists `status` AND appends the history row, and it emits
            // the one `updated` activity row (old=confirmed, new=cancelled).
            // A separate `update(['status' => ...])` before it was redundant and
            // dangerous: it would emit the activity row on its own, and the
            // `addStatus()` update right after would then be a no-op that logs
            // nothing — making the audit row depend on statement order rather
            // than on the transition itself.
            $locked->addStatus(Order::STATUS_CANCELLED, 'تم إلغاء الطلب');

            return true;
        });

        if ($cancelled === 'not_found') {
            return response()->json(['success' => false, 'message' => 'الطلب غير موجود'], 404);
        }

        if (is_array($cancelled) && isset($cancelled['ok']) && ! $cancelled['ok']) {
            return response()->json([
                'success' => false,
                'message' => $cancelled['message'],
                'data' => $cancelled['data'],
            ], $cancelled['status']);
        }

        if (! $cancelled) {
            // A concurrent request already cancelled (or completed) this order.
            return response()->json(['success' => false, 'message' => 'لا يمكن إلغاء طلب مكتمل'], 422);
        }

        $this->notifications->orderCancelled($order->fresh());

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

        $order->load(['pharmacy', 'items.pharmacyMedicine', 'items.pharmacy', 'subOrders.pharmacy', 'address', 'statusHistories']);

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
                'is_multi_pharmacy' => $order->subOrders->count() > 1,
                'sub_orders' => $order->subOrders->map(fn (SubOrder $s) => array_merge(
                    $this->subOrderPayload($s),
                    [
                        'status_histories' => $s->statusHistories->map(fn ($h) => [
                            'status' => $h->status,
                            'note' => $h->note,
                            'status_changed_at' => $h->status_changed_at?->toDateTimeString(),
                        ])->values(),
                    ]
                ))->values(),
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
                SubOrder::STATUS_PREPARING,
                SubOrder::STATUS_READY,
                SubOrder::STATUS_OUT_FOR_DELIVERY,
                SubOrder::STATUS_DELIVERED,
                SubOrder::STATUS_CANCELLED,
            ]),
            'note' => 'nullable|string|max:255',
        ]);

        $pharmacyId = $pharmacy->pharmacy?->id;

        // Phase 1 — a pharmacy may only touch ITS OWN sub-order inside the
        // parent order. This is the isolation guarantee for multi-pharmacy
        // orders: one pharmacy can never advance or cancel another's part.
        $subOrder = $order->subOrders()->where('pharmacy_id', $pharmacyId)->first();

        if ($subOrder) {
            return $this->applySubOrderStatus($order, $subOrder, $data['status'], $data['note'] ?? null);
        }

        // ── Legacy (pre-Phase-1) orders ────────────────────────────────────
        // These have no sub-orders; items hang directly off the order. We keep
        // the original behaviour so historical orders remain operable.
        $hasItemsFromPharmacy = $order->items()
            ->where('pharmacy_id', $pharmacyId)
            ->exists();

        if (! $hasItemsFromPharmacy) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تحديث حالة طلب لا يخص هذه الصيدلية',
            ], 403);
        }

        // Phase 0 — atomic, locked transition so a legacy cancellation restores
        // its stock exactly once and never leaves a half-applied state.
        $result = DB::transaction(function () use ($order, $data) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return ['ok' => false, 'status' => 404, 'message' => 'الطلب غير موجود', 'data' => []];
            }

            if (! $locked->canTransitionTo($data['status'])) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => "لا يمكن الانتقال من حالة {$locked->status} إلى {$data['status']}. الحالات المسموحة: " . $this->allowedListFor($locked->status),
                    'data' => [],
                ];
            }

            // Rider Delivery — الطلب المُسند لمندوب: مراحل التوصيل للمندوب فقط.
            if ($locked->rider_id && in_array($data['status'], [Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED], true)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'التوصيل مُسند إلى مندوب — لا يمكن للصيدلية تحديث مراحل التوصيل.',
                    'data' => [],
                ];
            }

            // Phase 4 — Decision 1: mirror the sub-order guard. A paid order may
            // not be cancelled by a pharmacy until a refund workflow exists. Runs
            // before stock restoration (restoreOrderInventory) and addStatus, so on
            // denial the order row is not mutated beyond the read. The row is
            // locked (lockForUpdate above), so the payment_status read is race-free.
            if ($data['status'] === Order::STATUS_CANCELLED && $locked->payment_status === Order::PAYMENT_PAID) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'لا يمكن إلغاء طلب تم دفعه دفعًا كاملاً دون استرداد.',
                    'data' => [
                        'code' => OrderCancellationPolicy::REFUND_CODE,
                        'payment_status' => $locked->payment_status,
                    ],
                ];
            }

            $denial = $this->paymentGate->denialFor($locked, $data['status']);

            if ($denial !== null) {
                return $denial;
            }

            if ($data['status'] === Order::STATUS_CANCELLED) {
                // Legacy order: no sub-orders, so restore its own lines once.
                $this->restoreOrderInventory($locked);
            }

            if ($data['status'] === Order::STATUS_DELIVERED) {
                $locked->update(['delivered_at' => now()]);
            }

            $locked->addStatus($data['status'], $data['note'] ?? null);

            return [
                'ok' => true,
                'status' => 200,
                'message' => 'تم تحديث حالة الطلب بنجاح',
                'data' => [
                    'order_id' => $locked->id,
                    'status' => $locked->status,
                ],
            ];
        });

        if ($result['ok']) {
            $this->notifications->legacyOrderStatusChanged($order->fresh(), $data['status']);
        }

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }

    /**
     * Phase 1 — pharmacy updates one of ITS OWN sub-orders by id.
     * Authorization is ownership-based: a pharmacy can only touch a sub-order
     * whose `pharmacy_id` matches its own.
     */
    public function updateSubOrderStatus(Request $request, SubOrder $subOrder): JsonResponse
    {
        $pharmacy = $request->user();

        abort_unless($pharmacy->role === 'pharmacy', 403);

        if ((int) $subOrder->pharmacy_id !== (int) ($pharmacy->pharmacy?->id)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تحديث حالة طلب لا يخص هذه الصيدلية',
            ], 403);
        }

        $data = $request->validate([
            'status' => 'required|string|in:' . implode(',', [
                SubOrder::STATUS_PREPARING,
                SubOrder::STATUS_READY,
                SubOrder::STATUS_OUT_FOR_DELIVERY,
                SubOrder::STATUS_DELIVERED,
                SubOrder::STATUS_CANCELLED,
            ]),
            'note' => 'nullable|string|max:255',
        ]);

        $order = $subOrder->order;

        return $this->applySubOrderStatus($order, $subOrder, $data['status'], $data['note'] ?? null);
    }

    /**
     * Phase 1 — pharmacy lists ONLY its own sub-orders across all orders.
     */
    public function pharmacySubOrders(Request $request): JsonResponse
    {
        $pharmacy = $request->user();

        abort_unless($pharmacy->role === 'pharmacy', 403);

        $pharmacyId = $pharmacy->pharmacy?->id;

        $subOrders = SubOrder::where('pharmacy_id', $pharmacyId ?? -1)
            ->with(['order.user', 'items', 'pharmacy'])
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الطلبات الفرعية بنجاح',
            'data' => collect($subOrders->items())->map(fn (SubOrder $s) => $this->pharmacySubOrderPayload($s))->values(),
            'pagination' => [
                'total' => $subOrders->total(),
                'per_page' => $subOrders->perPage(),
                'current_page' => $subOrders->currentPage(),
                'last_page' => $subOrders->lastPage(),
            ],
        ]);
    }

    public function pharmacySubOrder(Request $request, int $subOrder): JsonResponse
    {
        $pharmacyId = $request->user()->pharmacy?->id;

        $owned = SubOrder::query()
            ->where('pharmacy_id', $pharmacyId ?? -1)
            ->with(['order.user', 'items', 'pharmacy'])
            ->findOrFail($subOrder);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الطلب الفرعي بنجاح',
            'data' => $this->pharmacySubOrderPayload($owned),
        ]);
    }

    /**
     * Shared sub-order status transition + parent-status derivation.
     */
    private function applySubOrderStatus(Order $order, SubOrder $subOrder, string $newStatus, ?string $note): JsonResponse
    {
        // Phase 0 — one transaction so a cancellation and its stock restoration
        // are atomic: either both land or neither does.
        $result = DB::transaction(function () use ($order, $subOrder, $newStatus, $note) {
            // Lock the parent order FIRST — the same order used by payment
            // approval and patient cancellation — so the payment check cannot
            // race an approval. Never lock a child before its parent.
            $lockedOrder = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $lockedOrder) {
                return ['ok' => false, 'status' => 404, 'message' => 'الطلب غير موجود', 'data' => []];
            }

            // Lock the sub-order: a concurrent cancel must not restore the same
            // stock twice, and the transition must be re-validated under the lock.
            $locked = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->first();

            if (! $locked || (int) $locked->order_id !== (int) $lockedOrder->id) {
                return ['ok' => false, 'status' => 404, 'message' => 'الطلب الفرعي غير موجود', 'data' => []];
            }

            if (! $locked->canTransitionTo($newStatus)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => "لا يمكن الانتقال من حالة {$locked->status} إلى {$newStatus}. الحالات المسموحة: " . $this->allowedListFor($locked->status),
                    'data' => [],
                ];
            }

            // Rider Delivery — بعد إسناد الطلب لمندوب، تصبح مراحل التوصيل
            // (out_for_delivery/delivered) من اختصاص المندوب لا الصيدلية.
            //
            // ⚠️ `rider_id` عمود على **الطلب الأب** لا على الطلب الفرعي:
            // `$locked` هنا هو `SubOrder` (مقفول أعلاه)، وقراءة
            // `$locked->rider_id` كانت تُرجع null دائمًا لأن `sub_orders`
            // لا يحمل هذا العمود — فيسقط الحارس بصمت وتستطيع أي صيدلية
            // دفع طلب مُسند إلى مندوب. المصدر الصحيح هو `$lockedOrder`.
            // (نفس الحارس في مسار الطلبات القديمة يستخدم `$locked->rider_id`
            //  على `Order` — وهو صحيح هناك لأن `$locked` كائن طلب.)
            if ($lockedOrder->rider_id && in_array($newStatus, [SubOrder::STATUS_OUT_FOR_DELIVERY, SubOrder::STATUS_DELIVERED], true)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'التوصيل مُسند إلى مندوب — لا يمكن للصيدلية تحديث مراحل التوصيل.',
                    'data' => [],
                ];
            }

            // Phase 4 — Decision 1: a paid parent order may not be cancelled by a
            // pharmacy until an approved refund workflow exists. Re-use the patient
            // cancellation policy's `refund_required` code for a consistent API
            // surface. This runs before `denialFor`, before status change (L858),
            // and before stock restoration (L851) — so on denial nothing is written
            // except the read itself. The parent order row is locked (L808), so the
            // `payment_status` read cannot race an approval.
            if ($newStatus === SubOrder::STATUS_CANCELLED && $lockedOrder->payment_status === Order::PAYMENT_PAID) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'لا يمكن إلغاء طلب تم دفعه دفعًا كاملاً دون استرداد.',
                    'data' => [
                        'code' => OrderCancellationPolicy::REFUND_CODE,
                        'payment_status' => $lockedOrder->payment_status,
                    ],
                ];
            }

            $denial = $this->paymentGate->denialFor($lockedOrder, $newStatus);

            if ($denial !== null) {
                return $denial;
            }

            if ($newStatus === SubOrder::STATUS_CANCELLED) {
                // `cancelled` is terminal (canTransitionTo returns [] for it), so
                // this branch can run at most once per sub-order.
                $this->restoreSubOrderInventory($locked);
            }

            if ($newStatus === SubOrder::STATUS_DELIVERED) {
                $locked->update(['delivered_at' => now()]);
            }

            $locked->addStatus($newStatus, $note);

            // Parent status is DERIVED from the (now updated) set of sub-orders.
            $lockedOrder->load('subOrders');
            $lockedOrder->syncStatusFromSubOrders();

            $freshOrder = $lockedOrder->fresh();

            return [
                'ok' => true,
                'status' => 200,
                'message' => 'تم تحديث حالة الطلب بنجاح',
                'data' => [
                    'order_id' => $lockedOrder->id,
                    'sub_order_id' => $locked->id,
                    'status' => $locked->status,
                    'order_status' => $freshOrder->status,
                ],
                'sub_order' => $locked->fresh(['pharmacy.user']),
                'order' => $freshOrder,
            ];
        });

        if ($result['ok']) {
            $freshOrder = $result['order'];
            $freshSubOrder = $result['sub_order'];

            match ($newStatus) {
                SubOrder::STATUS_PREPARING => $this->notifications->subOrderPreparing($freshOrder, $freshSubOrder),
                SubOrder::STATUS_READY => $this->notifications->subOrderReady($freshOrder, $freshSubOrder),
                SubOrder::STATUS_CANCELLED => $this->notifications->orderCancelled($freshOrder, $freshSubOrder),
                SubOrder::STATUS_OUT_FOR_DELIVERY => $freshOrder->status === Order::STATUS_OUT_FOR_DELIVERY
                    ? $this->notifications->orderOutForDelivery($freshOrder)
                    : null,
                SubOrder::STATUS_DELIVERED => $freshOrder->status === Order::STATUS_DELIVERED
                    ? $this->notifications->orderDelivered($freshOrder)
                    : null,
                default => null,
            };
        }

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'data' => $result['data'],
        ], $result['status']);
    }

    /**
     * Phase 0 — true when the status is one in which the entity holds stock that
     * checkout deducted.
     *
     * Checkout decrements `pharmacy_medicines.quantity` and then moves the entity
     * straight to `confirmed` inside the same transaction, so `pending` means no
     * stock was ever taken and the two terminal states are already resolved.
     * Restoration is only valid from the statuses below.
     */
    private function holdsDeductedStock(?string $status): bool
    {
        return in_array($status, [
            Order::STATUS_CONFIRMED,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
            Order::STATUS_OUT_FOR_DELIVERY,
        ], true);
    }

    /**
     * Phase 0 — give back the stock held by ONE pharmacy sub-order.
     *
     * Idempotency comes from the caller: this runs only on the transition into
     * `cancelled`, which is terminal, so a sub-order is restocked at most once.
     * Callers must hold a row lock on the sub-order for the transaction.
     */
    private function restoreSubOrderInventory(SubOrder $subOrder): void
    {
        if (! $this->holdsDeductedStock($subOrder->status)) {
            return;
        }

        $this->restoreLines($subOrder->items()->get(['pharmacy_medicine_id', 'quantity']));
    }

    /**
     * Phase 0 — give back the stock held by a LEGACY (pre-Phase-1) order.
     *
     * Only lines with `sub_order_id IS NULL` are touched, so this can never
     * double-restore stock that already belongs to a sub-order.
     */
    private function restoreOrderInventory(Order $order): void
    {
        if (! $this->holdsDeductedStock($order->status)) {
            return;
        }

        $this->restoreLines(
            $order->items()->whereNull('sub_order_id')->get(['pharmacy_medicine_id', 'quantity'])
        );
    }

    /**
     * Mirror of the checkout decrement: hand back the exact recorded quantity.
     * Rows are incremented in place and `is_available` is left untouched, which
     * matches how checkout deducted the stock.
     */
    private function restoreLines(iterable $items): void
    {
        foreach ($items as $item) {
            $quantity = (int) $item->quantity;

            if (! $item->pharmacy_medicine_id || $quantity <= 0) {
                continue;
            }

            PharmacyMedicine::where('id', $item->pharmacy_medicine_id)
                ->increment('quantity', $quantity);
        }
    }

    private function allowedListFor(string $status): string
    {
        $allowed = [
            Order::STATUS_PENDING => 'confirmed',
            Order::STATUS_CONFIRMED => 'preparing, cancelled',
            Order::STATUS_PREPARING => 'ready, cancelled',
            Order::STATUS_READY => 'out_for_delivery, cancelled',
            Order::STATUS_OUT_FOR_DELIVERY => 'delivered, cancelled',
            Order::STATUS_DELIVERED => 'none',
            Order::STATUS_CANCELLED => 'none',
        ];

        return $allowed[$status] ?? 'none';
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'delivery_fee' => (float) $order->delivery_fee,
            'grand_total' => round((float) $order->total + (float) $order->delivery_fee, 2),
            'coupon_code' => $order->coupon_code,
            'address_snapshot' => $order->address_snapshot,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'items_count' => $order->items->count(),
            'is_multi_pharmacy' => $order->subOrders->count() > 1,
            'sub_orders' => collect($order->subOrders)
                ->map(fn (SubOrder $s) => $this->subOrderPayload($s))
                ->values(),
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
                'sub_order_id' => $item->sub_order_id,
                'pharmacy_name' => $item->pharmacy?->pharmacy_name,
                'medicine_trade_name' => $item->medicine_trade_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ])->values(),
            'created_at' => $order->created_at?->toDateTimeString(),
        ];
    }

    /**
     * Phase 1 — a sub-order as seen by the patient (and, in list form, by the
     * owning pharmacy). Kept in the same hand-built-array style as payload().
     */
    private function subOrderPayload(SubOrder $subOrder): array
    {
        return [
            'id' => $subOrder->id,
            'order_id' => $subOrder->order_id,
            'pharmacy_id' => $subOrder->pharmacy_id,
            'pharmacy_name' => $subOrder->pharmacy?->pharmacy_name,
            'subtotal' => (float) $subOrder->subtotal,
            'discount' => (float) $subOrder->discount,
            'shipping_fee' => (float) $subOrder->shipping_fee,
            'commission' => (float) $subOrder->commission,
            'total' => (float) $subOrder->total,
            'status' => $subOrder->status,
            'payment_status' => $subOrder->payment_status,
            'delivered_at' => $subOrder->delivered_at?->toDateTimeString(),
            'created_at' => $subOrder->created_at?->toDateTimeString(),
        ];
    }

    /** Pharmacy view: never serialize the parent items or parent financial totals. */
    private function pharmacySubOrderPayload(SubOrder $subOrder): array
    {
        $order = $subOrder->order;

        return array_merge($this->subOrderPayload($subOrder), [
            'patient' => $order?->user ? ['name' => $order->user->name] : null,
            'order_status' => $order?->status,
            'payment_status' => $order?->payment_status,
            // Checkout currently requires a delivery address; no pickup flow exists.
            'fulfillment_method' => 'delivery',
            'currency' => 'ILS',
            'items' => $subOrder->items
                ->filter(fn (OrderItem $item) => (int) $item->pharmacy_id === (int) $subOrder->pharmacy_id)
                ->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'medicine_trade_name' => $item->medicine_trade_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                ])->values(),
        ]);
    }

    private function payloadCollection($orders): array
    {
        return collect($orders)->map(fn ($order) => $this->payload($order))->values()->all();
    }
}
