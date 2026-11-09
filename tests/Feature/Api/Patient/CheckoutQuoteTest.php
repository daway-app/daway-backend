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
use App\Models\User;
use App\Services\DeliveryPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * معاينة ملخص الطلب قبل الدفع.
 *
 * العقد الذي تحرسه هذه الاختبارات:
 *   1. المعاينة والدفع يعطيان **نفس** `delivery_fee` و`grand_total`.
 *   2. المعاينة لا تكتب شيئًا: لا طلب، لا خصم مخزون، لا استهلاك كوبون.
 *   3. رسوم التوصيل عدد صحيح دائمًا (تقريب في منطق التسعير المشترك).
 *   4. تغيّر المبلغ بين المعاينة والدفع يُرفض 409 بلا إنشاء طلب.
 */
class CheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_LAT = 31.5;
    private const BASE_LNG = 34.47;
    private const METERS_PER_DEGREE_LAT = 111_194.9266;

    private Pharmacy $pharmacy;
    private PharmacyMedicine $pharmacyMedicine;
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

        // ~1500 متر شمال الصيدلية ⇒ 1.5 كم.
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

    private function quote(array $overrides = [])
    {
        return $this->postJson('/api/patient/checkout/quote', array_merge([
            'address_id' => $this->address->id,
        ], $overrides));
    }

    // ────────────────────────────────────────────────────────────────
    // 1) التطابق بين المعاينة والدفع
    // ────────────────────────────────────────────────────────────────

    public function test_quote_and_checkout_agree_on_delivery_fee_and_grand_total(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 12.5); // سعر أدمن عشري ⇒ يُقرَّب إلى 13

        $quote = $this->quote()
            ->assertOk()
            ->json('data');

        $this->assertSame(100.0, (float) $quote['subtotal']);
        $this->assertSame(13, $quote['delivery_fee']);
        $this->assertSame(113.0, (float) $quote['grand_total']);

        $checkout = $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'quote-match-1',
        ])->assertStatus(201)->json('data');

        // العقد الجوهري: نفس الرقمين قبل الدفع وبعده.
        $this->assertSame((float) $quote['delivery_fee'], (float) $checkout['delivery_fee']);
        $this->assertSame((float) $quote['grand_total'], (float) $checkout['grand_total']);
    }

    // ────────────────────────────────────────────────────────────────
    // 2) المعاينة لا تكتب
    // ────────────────────────────────────────────────────────────────

    public function test_a_quote_never_creates_an_order_or_touches_stock_or_coupon(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        $coupon = Coupon::create([
            'code' => 'QUOTE10',
            'type' => 'percentage',
            'value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => 5,
            'used_count' => 0,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);

        $this->quote(['coupon_code' => 'QUOTE10'])->assertOk();

        $this->assertSame(0, Order::count(), 'المعاينة يجب ألا تنشئ طلبًا');
        $this->assertSame(100, $this->pharmacyMedicine->fresh()->quantity, 'المخزون لا يُخصم');
        $this->assertSame(0, (int) $coupon->fresh()->used_count, 'الكوبون لا يُستهلك');
        $this->assertDatabaseCount('coupon_user', 0);
    }

    // ────────────────────────────────────────────────────────────────
    // 3) per_km
    // ────────────────────────────────────────────────────────────────

    public function test_per_km_mode_returns_an_integer_fee(): void
    {
        $this->seedCart();
        $this->enablePricing();
        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        DeliveryPricingService::setPerKmRate(6.0); // 6.0 × 1.5 كم = 9

        $data = $this->quote()->assertOk()->json('data');

        $this->assertSame('per_km', $data['pricing_mode']);
        $this->assertSame(9, $data['delivery_fee']);
        $this->assertIsInt($data['delivery_fee']);
        $this->assertSame(109.0, (float) $data['grand_total']);
    }

    // ────────────────────────────────────────────────────────────────
    // 4) حدود التقريب: 9.11 ← 9 · 9.50 ← 10 · 9.90 ← 10
    // ────────────────────────────────────────────────────────────────

    public function test_delivery_fee_rounding_boundaries(): void
    {
        $this->seedCart();
        $this->enablePricing();

        $cases = [
            [9.11, 9],
            [9.50, 10],
            [9.90, 10],
            [12.5, 13],
        ];

        foreach ($cases as [$configured, $expected]) {
            DeliveryFeeTier::query()->delete();
            $this->tier(0, null, $configured);

            $data = $this->quote()->assertOk()->json('data');

            $this->assertSame($expected, $data['delivery_fee'], "الشرحة {$configured} يجب أن تصبح {$expected}");
            // الإجمالي يُحسب من الرسوم بعد التقريب لا قبله.
            $this->assertSame((float) (100 + $expected), (float) $data['grand_total']);
        }
    }

    public function test_per_km_fee_is_rounded_not_truncated(): void
    {
        $this->seedCart();
        $this->enablePricing();
        DeliveryPricingService::setMode(DeliveryPricingService::MODE_PER_KM);
        // 6.34 × 1.5 = 9.51 ⇒ 10 (القص كان سيعطي 9)
        DeliveryPricingService::setPerKmRate(6.34);

        $data = $this->quote()->assertOk()->json('data');

        $this->assertSame(10, $data['delivery_fee']);
    }

    // ────────────────────────────────────────────────────────────────
    // 5) تعدد الصيدليات
    // ────────────────────────────────────────────────────────────────

    public function test_multi_pharmacy_quote_charges_one_fee_for_the_route(): void
    {
        $pharmacy2 = Pharmacy::factory()->create([
            'is_active' => true,
            'latitude' => self::BASE_LAT + (300.0 / self::METERS_PER_DEGREE_LAT),
            'longitude' => self::BASE_LNG,
        ]);

        $medicine2 = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy2->id,
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
            'price' => 50.00,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'pharmacy_id' => $pharmacy2->id,
            'pharmacy_medicine_id' => $medicine2->id,
            'quantity' => 1,
            'price' => 30.00,
        ]);

        $this->enablePricing();
        $this->tier(0, 2000, 7);

        $data = $this->quote()->assertOk()->json('data');

        $this->assertSame(130.0, (float) $data['subtotal']);
        // رسوم واحدة على الطلب كله، لا مجموع رسوم لكل صيدلية.
        $this->assertSame(7, $data['delivery_fee']);
        $this->assertSame(137.0, (float) $data['grand_total']);
    }

    // ────────────────────────────────────────────────────────────────
    // 6) تغيير العنوان يغيّر الرسوم
    // ────────────────────────────────────────────────────────────────

    public function test_the_fee_changes_when_the_address_changes(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, 2000, 5);
        $this->tier(2001, null, 20);

        $near = $this->quote()->assertOk()->json('data');
        $this->assertSame(5, $near['delivery_fee']);

        $far = Address::factory()->for($this->patient)->create([
            'latitude' => self::BASE_LAT + (5000.0 / self::METERS_PER_DEGREE_LAT),
            'longitude' => self::BASE_LNG,
        ]);

        $data = $this->quote(['address_id' => $far->id])->assertOk()->json('data');

        $this->assertSame(20, $data['delivery_fee']);
        $this->assertGreaterThan($near['distance_meters'], $data['distance_meters']);
    }

    // ────────────────────────────────────────────────────────────────
    // 7) الكوبونات
    // ────────────────────────────────────────────────────────────────

    public function test_a_valid_coupon_is_reflected_in_the_quote(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        Coupon::create([
            'code' => 'TEN',
            'type' => 'percentage',
            'value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => 0,
            'used_count' => 0,
            'per_user_limit' => 0,
            'is_active' => true,
        ]);

        $data = $this->quote(['coupon_code' => 'TEN'])->assertOk()->json('data');

        $this->assertSame(10.0, (float) $data['discount']);
        $this->assertSame(90.0, (float) $data['total']);
        $this->assertSame(103.0, (float) $data['grand_total']);
    }

    public function test_an_invalid_coupon_is_rejected_without_writing(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        $this->quote(['coupon_code' => 'NOPE'])->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->pharmacyMedicine->fresh()->quantity);
    }

    // ────────────────────────────────────────────────────────────────
    // 8) رفض الدفع عند تغيّر المبلغ المعروض
    // ────────────────────────────────────────────────────────────────

    public function test_checkout_rejects_when_the_quoted_delivery_fee_changed(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        $quoted = $this->quote()->assertOk()->json('data');

        // تغيّر سعر الأدمن بعد المعاينة.
        DeliveryFeeTier::query()->delete();
        $this->tier(0, null, 40);

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'quoted_delivery_fee' => $quoted['delivery_fee'],
            'quoted_grand_total' => $quoted['grand_total'],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'quote_changed')
            ->assertJsonPath('data.changes.delivery_fee.quoted', 13)
            ->assertJsonPath('data.changes.delivery_fee.current', 40);

        $this->assertSame(0, Order::count(), 'لا طلب عند رفض المبلغ');
        $this->assertSame(100, $this->pharmacyMedicine->fresh()->quantity, 'لا خصم مخزون');
    }

    public function test_checkout_accepts_a_matching_quote(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        $quoted = $this->quote()->assertOk()->json('data');

        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'quoted_delivery_fee' => $quoted['delivery_fee'],
            'quoted_grand_total' => $quoted['grand_total'],
            'idempotency_key' => 'matching-quote',
        ])->assertStatus(201);
    }

    public function test_checkout_without_quote_fields_stays_backward_compatible(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        // عميل قديم لا يرسل أي حقل معاينة.
        $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
        ])->assertStatus(201);
    }

    // ────────────────────────────────────────────────────────────────
    // 9) فشل المعاينة لا يكتب
    // ────────────────────────────────────────────────────────────────

    public function test_a_failed_quote_writes_nothing(): void
    {
        $this->seedCart();
        // التسعير معطّل ⇒ فشل.
        $this->quote()->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->pharmacyMedicine->fresh()->quantity);
    }

    public function test_a_quote_with_a_foreign_address_is_rejected(): void
    {
        $this->seedCart();
        $this->enablePricing();
        $this->tier(0, null, 13);

        $other = User::factory()->patient()->create();
        $foreign = Address::factory()->for($other)->create();

        $this->quote(['address_id' => $foreign->id])->assertStatus(422);
    }
}
