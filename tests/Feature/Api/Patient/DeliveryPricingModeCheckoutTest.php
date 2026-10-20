<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\DeliveryFeeTier;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\DeliveryPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تكامل «وضع التسعير» مع الـcheckout.
 *
 * يحرس العقود التالية:
 *   1. وضع الشرائح: الشريحة المطابقة سعرها **نهائي** ولا يُضاف إليها السعر
 *      الأساسي، حتى لو كان الأساسي مُضبوطًا.
 *   2. وضع الشرائح: عند غياب شريحة مطابقة يُستخدم السعر الأساسي كسعر **بديل**؛
 *      وإن لم يُضبط السعر الأساسي يُرفض الطلب.
 *   3. وضع السعر لكل كيلومتر: الرسوم = rate × المسافة(كم)، وتُتجاهل الشرائح
 *      والسعر الأساسي كليًا.
 *   4. الإحداثيات غير الصالحة/المسافة المتعذّرة ⇒ رفض دائم في كل الأوضاع —
 *      لا يُستبدل بأي سعر بديل.
 *   5. تغيّر السعر عبر مسار الأدمن يؤثر على الطلبات الجديدة فقط؛ اللقطة ثابتة.
 *   6. متعدد الصيدليات + كوبون: رسوم واحدة على الأب، والإجمالي النهائي صحيح
 *      (`total + delivery_fee`)، وأبناء الطلب تبقى `shipping_fee = 0`.
 *   7. التسعير معطّل أو مفتاح تفعيله **غائب** ⇒ رفض الطلب (لا رسوم صفر صامتة)،
 *      بلا إنشاء أي طلب أب أو فرعي ولا استهلاك مخزون.
 *   8. وضع السعر/كم بمسافة صفر ⇒ رسوم 0 مع **إنشاء** الطلب (الصفر سعر صالح لا فشل).
 */
class DeliveryPricingModeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_LAT = 31.5;
    private const BASE_LNG = 34.47;

    /** متر لكل درجة عرض عند نصف قطر 6371 كم — نفس ثابت Haversine. */
    private const METERS_PER_DEGREE_LAT = 111_194.9266;

    private Pharmacy $pharmacy;
    private PharmacyMedicine $pharmacyMedicine;
    private Pharmacy $pharmacy2;
    private PharmacyMedicine $pharmacyMedicine2;
    private User $patient;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pharmacy = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => self::BASE_LAT,
            'longitude' => self::BASE_LNG,
        ]);

        $this->pharmacyMedicine = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 50.00,
        ]);

        $this->patient = User::factory()->patient()->create();

        // العنوان على بعد ~1500 متر شمال الصيدلية.
        $this->address = Address::factory()->for($this->patient)->create([
            'latitude' => self::BASE_LAT + (1500.0 / self::METERS_PER_DEGREE_LAT),
            'longitude' => self::BASE_LNG,
        ]);

        $this->actingAs($this->patient, 'sanctum');
    }

    private function seedCart(int $quantity = 2): Cart
    {
        $cart = Cart::factory()->for($this->patient)->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => $quantity,
            'price' => (float) $this->pharmacyMedicine->price,
        ]);

        return $cart;
    }

    /** سلة متعددة الصيدليات: صيدلية ثانية على 300م شمالًا (ضمن حد التقارب 500م). */
    private function seedMultiPharmacyCart(): Cart
    {
        $this->pharmacy2 = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => self::BASE_LAT + (300.0 / self::METERS_PER_DEGREE_LAT),
            'longitude' => self::BASE_LNG,
        ]);

        $this->pharmacyMedicine2 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $this->pharmacy2->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => 30.00,
        ]);

        $cart = Cart::factory()->for($this->patient)->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
            'price' => (float) $this->pharmacyMedicine->price,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy2->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine2->id,
            'quantity' => 1,
            'price' => (float) $this->pharmacyMedicine2->price,
        ]);

        return $cart;
    }

    private function tier(int $min, ?int $max, float $fee, bool $active = true): DeliveryFeeTier
    {
        return DeliveryFeeTier::create([
            'min_distance_meters' => $min,
            'max_distance_meters' => $max,
            'fee' => $fee,
            'is_active' => $active,
        ]);
    }

    private function checkout()
    {
        return $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);
    }

    // ── التسعير معطّل/غير مُهيّأ ⇒ رفض الطلب ─────────────────────────────────

    public function test_checkout_is_rejected_when_pricing_is_disabled(): void
    {
        // الافتراضي: لا تفعيل صريح ⇒ التسعير معطّل.
        DeliveryPricingService::setEnabled(false);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب عندما يكون التسعير معطّلًا.');
    }

    public function test_checkout_is_rejected_when_the_enabled_key_is_missing(): void
    {
        // مفتاح التفعيل غائب كليًا (لا صف إعدادات) — نفس الرفض.
        $this->assertDatabaseMissing('settings', ['key' => 'delivery_pricing_enabled']);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count());
    }

    public function test_checkout_succeeds_when_pricing_is_enabled_and_configured(): void
    {
        $this->tier(0, null, 5.0);
        DeliveryPricingService::setEnabled(true);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 5);

        $this->assertSame(1, Order::count());
    }

    public function test_rejection_with_disabled_pricing_creates_no_parent_or_sub_orders(): void
    {
        DeliveryPricingService::setEnabled(false);

        $cart = $this->seedMultiPharmacyCart();

        $stockBeforeA = (int) $this->pharmacyMedicine->fresh()->quantity;
        $stockBeforeB = (int) $this->pharmacyMedicine2->fresh()->quantity;

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب أب عند الرفض.');
        $this->assertSame(0, SubOrder::count(), 'لا طلبات فرعية عند الرفض.');
        $this->assertSame(2, $cart->fresh()->items()->count(), 'السلة تبقى كما هي دون تفريغ.');
        $this->assertSame($stockBeforeA, (int) $this->pharmacyMedicine->fresh()->quantity, 'مخزون الصيدلية الأولى لم ينقص.');
        $this->assertSame($stockBeforeB, (int) $this->pharmacyMedicine2->fresh()->quantity, 'مخزون الصيدلية الثانية لم ينقص.');
    }

    // ── وضع السعر لكل كيلومتر: مسافة صفر ⇒ رسوم 0 (سعر صالح) ──────────────────

    public function test_per_km_mode_with_zero_distance_charges_zero_fee(): void
    {
        // العنوان فوق الصيدلية تمامًا ⇒ المسافة 0 ⇒ رسوم 0 (لا رفض).
        $this->address->update(['latitude' => self::BASE_LAT, 'longitude' => self::BASE_LNG]);

        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        DeliveryPricingService::setPerKmRate(3.0);
        DeliveryPricingService::setEnabled(true);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.grand_total', 100);

        $this->assertSame(1, Order::count());
    }

    // ── وضع الشرائح: الشريحة سعر نهائي بلا إضافة ─────────────────────────────

    public function test_matching_tier_is_the_final_fee_and_does_not_add_the_base_fee(): void
    {
        $this->tier(0, null, 12.5);
        DeliveryPricingService::setEnabled(true);
        DeliveryPricingService::setBaseFee(9.0); // يجب ألّا يُضاف ولا يُستبدل.

        $this->seedCart();

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 12.5)
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.grand_total', 112.5);
    }

    // ── وضع الشرائح: السعر الأساسي بديل عند غياب شريحة ───────────────────────

    public function test_base_fee_is_the_fallback_fee_when_no_tier_matches(): void
    {
        // شريحة قصيرة لا تغطي ~1500 م.
        $this->tier(0, 100, 5.0);
        DeliveryPricingService::setEnabled(true);
        DeliveryPricingService::setBaseFee(20.0);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 20)
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.grand_total', 120);
    }

    public function test_checkout_is_rejected_when_no_tier_matches_and_no_base_fee(): void
    {
        $this->tier(0, 100, 5.0);
        DeliveryPricingService::setEnabled(true);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب عند غياب شريحة وسعر أساسي.');
    }

    /**
     * رفض «لا شريحة مطابقة ولا سعر أساسي» يجب ألّا يترك أثرًا جزئيًا:
     * لا طلب أب/فرعي، ولا نقص مخزون، ولا استهلاك كوبون، والسلة كما هي.
     */
    public function test_rejection_without_a_matching_tier_or_base_fee_leaves_no_partial_state(): void
    {
        // شريحة قصيرة (0–100م) لا تغطي ~1500م، وبلا سعر أساسي بديل.
        $this->tier(0, 100, 5.0);
        DeliveryPricingService::setEnabled(true);
        $this->assertNull(DeliveryPricingService::baseFee());

        $coupon = Coupon::create([
            'code' => 'NORESET',
            'type' => 'fixed',
            'value' => 15,
            'minimum_order_amount' => 50,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $cart = $this->seedMultiPharmacyCart();

        $stockBeforeA = (int) $this->pharmacyMedicine->fresh()->quantity;
        $stockBeforeB = (int) $this->pharmacyMedicine2->fresh()->quantity;

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => $coupon->code,
            'idempotency_key' => 'reject-nonce-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب أب عند رفض التسعير.');
        $this->assertSame(0, SubOrder::count(), 'لا طلبات فرعية عند رفض التسعير.');
        $this->assertSame($stockBeforeA, (int) $this->pharmacyMedicine->fresh()->quantity, 'مخزون الصيدلية الأولى لم ينقص.');
        $this->assertSame($stockBeforeB, (int) $this->pharmacyMedicine2->fresh()->quantity, 'مخزون الصيدلية الثانية لم ينقص.');
        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'used_count لم يزد.');
        $this->assertSame(0, (int) $coupon->fresh()->users_used_count, 'users_used_count لم يزد.');
        $this->assertSame(0, DB::table('coupon_user')->count(), 'لا استهلاك للكوبون من المستخدم.');
        $this->assertSame(0, DB::table('checkout_idempotencies')->count(), 'لا تسجيل لمفتاح idempotency.');
        $this->assertSame(1, Cart::where('user_id', $this->patient->id)->count(), 'السلة نفسها باقية ولم تُحذف.');
        $this->assertSame(2, $cart->fresh()->items()->count(), 'السلة تبقى كما هي دون تفريغ.');
    }

    // ── وضع السعر لكل كيلومتر ────────────────────────────────────────────────

    public function test_per_km_mode_charges_rate_times_distance_and_ignores_tiers_and_base(): void
    {
        // شريحة سعرها ضخم وتغطي المسافة — يجب تجاهلها كليًا في وضع السعر/كم.
        $this->tier(0, null, 999.0);
        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        DeliveryPricingService::setBaseFee(77.0);        // يجب تجاهله.
        DeliveryPricingService::setPerKmRate(2.0);       // 1.5 كم × 2 = 3.0
        DeliveryPricingService::setEnabled(true);

        $this->seedCart();

        $response = $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.total', 100);

        // المسافة ~1500م؛ نتحقق من القيمة الفعلية ضمن نطاق ضيّق (تقريب مسافة).
        $fee = (float) Order::first()->delivery_fee;
        $this->assertGreaterThan(2.98, $fee);
        $this->assertLessThan(3.02, $fee);

        $grandTotal = (float) $response->json('data.grand_total');
        $this->assertGreaterThan(102.98, $grandTotal);
        $this->assertLessThan(103.02, $grandTotal);
        $this->assertSame(round(100 + $fee, 2), $grandTotal, 'الإجمالي = total + رسوم.');
    }

    public function test_per_km_mode_is_rejected_when_no_rate_is_configured(): void
    {
        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        DeliveryPricingService::setEnabled(true); // تفعيل مباشر بلا معدل — إعداد ناقص.

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count());
    }

    // ── الإحداثيات غير الصالحة: رفض دائم بلا سعر بديل ────────────────────────

    public function test_invalid_coordinates_are_rejected_in_per_km_mode_without_fallback(): void
    {
        $this->pharmacy->update(['latitude' => null, 'longitude' => null]);
        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        DeliveryPricingService::setPerKmRate(2.0);
        DeliveryPricingService::setEnabled(true);

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count());
    }

    public function test_invalid_coordinates_are_rejected_even_when_a_base_fee_is_set(): void
    {
        $this->pharmacy->update(['latitude' => null, 'longitude' => null]);
        $this->tier(0, null, 5.0);
        DeliveryPricingService::setEnabled(true);
        DeliveryPricingService::setBaseFee(20.0); // يجب ألّا يُخفي فشل الإحداثيات.

        $this->seedCart();

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count());
    }

    // ── تغيّر السعر يؤثر على الطلبات الجديدة فقط ──────────────────────────────

    public function test_price_change_affects_only_new_checkouts(): void
    {
        $tier = $this->tier(0, null, 10.0);
        DeliveryPricingService::setEnabled(true);

        $cart = $this->seedCart();
        $this->checkout()->assertStatus(201)->assertJsonPath('data.delivery_fee', 10);

        $first = Order::first();

        // غيّر الرسوم كما يفعل الأدمن، ثم اشترِ مرة أخرى بعنصر سلة جديد.
        $tier->update(['fee' => 25.0]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
            'price' => (float) $this->pharmacyMedicine->price,
        ]);

        $this->checkout()->assertStatus(201)->assertJsonPath('data.delivery_fee', 25);

        $this->assertSame(10.0, (float) $first->fresh()->delivery_fee, 'اللقطة القديمة ثابتة.');
        $this->assertSame(25.0, (float) Order::query()->orderByDesc('id')->first()->delivery_fee);
    }

    // ── متعدد الصيدليات + كوبون + الإجمالي النهائي ────────────────────────────

    public function test_multi_pharmacy_with_coupon_keeps_a_single_fee_and_correct_grand_total(): void
    {
        // المسار ≈1500م ⇒ داخل [0,2000] (رسوم 7)، لا مجموع المسافتين (~2700م).
        $this->tier(0, 2000, 7.0);
        DeliveryPricingService::setEnabled(true);

        $coupon = Coupon::create([
            'code' => 'MULTI15',
            'type' => 'fixed',
            'value' => 15,
            'minimum_order_amount' => 50,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->seedMultiPharmacyCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'coupon_code' => $coupon->code,
        ])->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 7)
            ->assertJsonPath('data.discount', 15)
            ->assertJsonPath('data.total', 115)
            ->assertJsonPath('data.grand_total', 122)
            ->assertJsonPath('data.sub_orders_count', 2);

        $order = Order::first();
        $this->assertSame(7.0, (float) $order->delivery_fee, 'رسوم واحدة على الطلب الأب.');
        $this->assertSame(115.0, (float) $order->total, 'الإجمالي = subtotal - discount.');
        $this->assertSame(0.0, (float) $order->subOrders()->sum('shipping_fee'), 'لا رسوم على الأبناء.');

        // ضبط تحكّم مقابل: النجاح يستهلك الكوبون فعلًا (فيصبح صفر الرفض ذا معنى).
        $this->assertSame(1, (int) $coupon->fresh()->used_count, 'يُستهلك الكوبون مرة واحدة عند النجاح.');
        $this->assertSame(1, (int) $coupon->fresh()->users_used_count);
        $this->assertSame(1, DB::table('coupon_user')->count());
    }
}
