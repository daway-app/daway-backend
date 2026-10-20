<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryFeeTier;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Services\DeliveryPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تكامل تسعير التوصيل مع الـcheckout (Phase 1).
 *
 * يحرس العقود التالية:
 *   1. عند تعطيل التسعير (أو غياب مفتاح التفعيل): يُرفض الطلب 422، لا رسوم
 *      صفر صامتة ولا طلب.
 *   2. عند التفعيل + وجود شريحة مطابقة: تُحتسب الرسوم في الخادم وتُحفظ **لقطة**
 *      على الطلب، ولا يدخل المبلغ في `total` (الإجمالي النهائي = total + fee).
 *   3. عند التفعيل + غياب شريحة مطابقة (أو إحداثيات): يُرفض الطلب 422 **بلا**
 *      إنشاء طلب ولا خصم مخزون ولا مسح سلة.
 *   4. قيمة العميل لا تُقرأ — إرسال `delivery_fee` مزوّر لا أثر له.
 *   5. اللقطة لا تتغيّر عند تعديل الشرائح لاحقًا.
 */
class DeliveryFeeCheckoutTest extends TestCase
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

    private function seedCart(): Cart
    {
        $cart = Cart::factory()->for($this->patient)->create();

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_medicine_id' => $this->pharmacyMedicine->id,
            'quantity' => 2,
            'price' => (float) $this->pharmacyMedicine->price,
        ]);

        return $cart;
    }

    /**
     * سلة متعددة الصيدليات: صيدلية ثانية على 300م شمالًا (ضمن حد التقارب 500م)،
     * وعنصر من كل صيدلية. مسار التوصيل المفتوح ≈ 1500م، بينما مجموع مسافتي
     * الصيدليتين على حدة ≈ 2700م — التمييز الذي تحرسه اختبارات الرسوم.
     */
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

    private function enablePricing(): void
    {
        DeliveryPricingService::setEnabled(true);
    }

    // ── الوضع المعطّل ─────────────────────────────────────────────────────

    public function test_checkout_is_rejected_when_pricing_is_disabled(): void
    {
        $this->seedCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب صامت برسوم صفر.');
    }

    // ── الوضع المفعّل ─────────────────────────────────────────────────────

    public function test_checkout_applies_the_matching_tier_fee_and_snapshots_it(): void
    {
        $this->tier(0, null, 12.5);
        $this->enablePricing();
        $this->seedCart();

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 12.5)
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.grand_total', 112.5);

        $order = Order::first();
        $this->assertSame(12.5, (float) $order->delivery_fee);
        $this->assertSame(100.0, (float) $order->total, 'الرسوم لا تدخل في total.');
    }

    public function test_checkout_is_rejected_when_no_tier_matches_the_distance(): void
    {
        // شريحة قصيرة لا تغطي ~1500 م.
        $this->tier(0, 100, 5.0);
        $this->enablePricing();

        $cart = $this->seedCart();
        $stockBefore = $this->pharmacyMedicine->fresh()->quantity;

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب عند رفض الرسوم.');
        $this->assertSame($stockBefore, $this->pharmacyMedicine->fresh()->quantity, 'لا خصم مخزون.');
        $this->assertSame(1, $cart->items()->count(), 'السلة تبقى كما هي.');
    }

    public function test_checkout_is_rejected_when_pharmacy_coordinates_are_missing(): void
    {
        $this->pharmacy->update(['latitude' => null, 'longitude' => null]);

        $this->tier(0, null, 5.0);
        $this->enablePricing();
        $this->seedCart();

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count());
    }

    public function test_a_client_supplied_fee_is_ignored(): void
    {
        $this->tier(0, null, 12.5);
        $this->enablePricing();
        $this->seedCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'delivery_fee' => 0, // محاولة تجاوز — يجب أن تُتجاهل.
        ])->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 12.5);

        $this->assertSame(12.5, (float) Order::first()->delivery_fee);
    }

    public function test_the_snapshotted_fee_is_stable_after_tier_changes(): void
    {
        $tier = $this->tier(0, null, 12.5);
        $this->enablePricing();
        $this->seedCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $order = Order::first();

        // غيّر الرسوم لاحقًا: اللقطة يجب ألّا تتأثر.
        $tier->update(['fee' => 99.0]);

        $this->assertSame(12.5, (float) $order->fresh()->delivery_fee);
    }

    // ── متعدد الصيدليات: رسوم مسار واحدة (TSP مفتوح) ─────────────────────────

    public function test_multi_pharmacy_checkout_applies_a_single_route_fee_not_the_sum(): void
    {
        // المسار ≈1500م ⇒ داخل [0,2000]؛ ومجموع المسافتين على حدة ≈2700م ⇒
        // داخل [2001,…]. الرسوم يجب أن تكون 7 (المسار) لا 40 (المجموع).
        $this->tier(0, 2000, 7.0);
        $this->tier(2001, null, 40.0);
        $this->enablePricing();
        $this->seedMultiPharmacyCart();

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 7)
            ->assertJsonPath('data.total', 130)
            ->assertJsonPath('data.grand_total', 137)
            ->assertJsonPath('data.sub_orders_count', 2);

        $order = Order::first();
        $this->assertSame(7.0, (float) $order->delivery_fee, 'رسوم واحدة على الطلب.');
        $this->assertNotSame(40.0, (float) $order->delivery_fee, 'ليست مجموع رسوم الصيدليات.');
        $this->assertSame(130.0, (float) $order->total, 'الرسوم لا تدخل في total.');
        $this->assertSame(0.0, (float) $order->subOrders()->sum('shipping_fee'), 'الرسوم على الأب فقط.');
    }

    public function test_multi_pharmacy_fee_uses_the_tier_above_a_lower_boundary(): void
    {
        // الحد عند 1490 فيقع المسار (≈1500) في الشريحة الأعلى.
        $this->tier(0, 1490, 3.0);
        $this->tier(1491, null, 50.0);
        $this->enablePricing();
        $this->seedMultiPharmacyCart();

        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 50);
    }

    public function test_multi_pharmacy_fee_uses_the_tier_below_a_higher_boundary(): void
    {
        // الحد عند 1510 فيقع المسار (≈1500) في الشريحة الأدنى.
        $this->tier(0, 1510, 3.0);
        $this->tier(1511, null, 50.0);
        $this->enablePricing();
        $this->seedMultiPharmacyCart();

        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_fee', 3);
    }

    public function test_multi_pharmacy_checkout_is_rejected_when_a_pharmacy_lacks_coordinates(): void
    {
        $this->tier(0, null, 5.0);
        $this->enablePricing();
        $cart = $this->seedMultiPharmacyCart();

        // صيدلية ثانية بلا إحداثيات: بوابة التقارب تفشل صراحةً قبل التسعير.
        $this->pharmacy2->update(['latitude' => null, 'longitude' => null]);

        $response = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pharmacy_distance']);

        $this->assertSame(0, Order::count(), 'لا طلب عند رفض التقارب.');
        $this->assertSame(2, $cart->items()->count(), 'السلة تبقى كما هي.');
    }

    public function test_multi_pharmacy_snapshotted_fee_is_stable_after_tier_changes(): void
    {
        $tier = $this->tier(0, null, 12.5);
        $this->enablePricing();
        $this->seedMultiPharmacyCart();

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(12.5, (float) $order->delivery_fee);

        $tier->update(['fee' => 99.0]);

        $this->assertSame(12.5, (float) $order->fresh()->delivery_fee, 'اللقطة لا تتأثر بتغيّر الشرائح.');
    }

    public function test_multi_pharmacy_checkout_is_rejected_when_pricing_is_disabled(): void
    {
        $cart = $this->seedMultiPharmacyCart();

        $this->postJson('/api/patient/checkout', ['address_id' => $this->address->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_fee']);

        $this->assertSame(0, Order::count(), 'لا طلب صامت برسوم صفر.');
        $this->assertSame(2, $cart->items()->count(), 'السلة تبقى كما هي.');
    }
}
