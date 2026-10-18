<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 2 — حد التقارب بين صيدليات الطلب الواحد (500 متر).
 *
 * القاعدة: أبعد زوج من صيدليات الطلب يجب ألا يتجاوز 500 متر. المسافة 500
 * بالضبط مسموحة؛ الرفض عند > 500 فقط.
 *
 * الاختبارات تُثبت السلوك من الخارج (HTTP + قاعدة البيانات) لا من داخل
 * الخدمة، وأهمّها آخر اثنين: أن الرفض **لا يترك أثراً** (لا طلب ولا بند
 * ولا خصم مخزون ولا سلة مفرَّغة).
 */
class PharmacyProximityCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** نقطة أساس في غزة — كل الصيدليات تُوضع بإزاحة شمالية عنها. */
    private const BASE_LAT = 31.5;
    private const BASE_LNG = 34.47;

    /** متر لكل درجة عرض عند نصف قطر 6371 كم (نفس ثابت Haversine). */
    private const METERS_PER_DEGREE_LAT = 111_194.9266;

    private User $patient;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patient = User::factory()->patient()->create();
        $this->address = Address::factory()->for($this->patient)->create([
            'latitude' => self::BASE_LAT,
            'longitude' => self::BASE_LNG,
        ]);

        $this->actingAs($this->patient, 'sanctum');
    }

    /** صيدلية على مسافة `$northMeters` شمال نقطة الأساس. */
    private function pharmacyAt(float $northMeters, string $name): Pharmacy
    {
        return Pharmacy::factory()->create([
            'is_active' => true,
            'pharmacy_name' => $name,
            'latitude' => self::BASE_LAT + ($northMeters / self::METERS_PER_DEGREE_LAT),
            'longitude' => self::BASE_LNG,
        ]);
    }

    private function pharmacyWithoutCoordinates(string $name): Pharmacy
    {
        return Pharmacy::factory()->create([
            'is_active' => true,
            'pharmacy_name' => $name,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    private function stockFor(Pharmacy $pharmacy, float $price = 50.00): PharmacyMedicine
    {
        return PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 100,
            'is_available' => true,
            'price' => $price,
        ]);
    }

    /** @param  list<PharmacyMedicine>  $lines */
    private function seedCart(array $lines): Cart
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $this->patient->id],
            ['items_count' => 0, 'subtotal' => 0]
        );
        $cart->items()->delete();

        foreach ($lines as $pm) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'pharmacy_id' => $pm->pharmacy_id,
                'pharmacy_medicine_id' => $pm->id,
                'quantity' => 1,
                'price' => (float) $pm->price,
            ]);
        }

        $cart->refresh();

        return $cart;
    }

    private function checkout(): TestResponse
    {
        return $this->postJson('/api/patient/checkout', [
            'address_id' => $this->address->id,
            'idempotency_key' => 'prox-'.uniqid(),
        ]);
    }

    // ── الحالات المسموحة ─────────────────────────────────────────────────

    public function test_two_pharmacies_under_the_limit_are_accepted(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(350, 'صيدلية ب');

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.sub_orders_count', 2);

        $this->assertSame(2, SubOrder::count());
    }

    public function test_two_pharmacies_exactly_at_the_limit_are_accepted(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(500, 'صيدلية ب');

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        // 500 متر بالضبط مسموحة. (الإحداثيات تُخزَّن بـdecimal(10,8) أي بدقة
        // ~1.11 مم، فالمسافة المحسوبة هنا 500.0002 م — والهامش العددي 1 مم
        // يستوعب دقة التخزين هذه دون أن يصبح تسامحاً في حد التوصيل.)
        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.sub_orders_count', 2);
    }

    /**
     * الحد صارم: 500.4 م كانت تُقبل سابقاً لأن `(int) round(500.4) = 500`.
     * الآن تُرفض لأنها أكبر من 500 م.
     */
    public function test_two_pharmacies_slightly_over_the_limit_are_rejected(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(500.4, 'صيدلية ب');

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        $response = $this->checkout()->assertStatus(422);
        $response->assertJsonValidationErrors('pharmacy_distance');

        $message = (string) $response->json('errors.pharmacy_distance.0');

        $this->assertStringContainsString('500.4', $message);
        $this->assertStringContainsString('500', $message);
    }

    public function test_three_close_pharmacies_are_accepted(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(200, 'صيدلية ب');
        $c = $this->pharmacyAt(450, 'صيدلية ج');

        $this->seedCart([
            $this->stockFor($a),
            $this->stockFor($b, 30.00),
            $this->stockFor($c, 20.00),
        ]);

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.sub_orders_count', 3);
    }

    // ── الرفض ────────────────────────────────────────────────────────────

    public function test_two_pharmacies_over_the_limit_are_rejected(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(620, 'صيدلية ب');

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        $response = $this->checkout()->assertStatus(422);

        $response->assertJsonValidationErrors('pharmacy_distance');

        $message = (string) $response->json('errors.pharmacy_distance.0');

        $this->assertStringContainsString('صيدلية أ', $message);
        $this->assertStringContainsString('صيدلية ب', $message);
        $this->assertStringContainsString('620', $message);
        $this->assertStringContainsString('500', $message);
    }

    public function test_farthest_pair_is_checked_not_only_adjacent_pairs(): void
    {
        // A=0 · B=350 · C=700 — A↔B و B↔C داخل الحد، لكن A↔C خارجه.
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(350, 'صيدلية ب');
        $c = $this->pharmacyAt(700, 'صيدلية ج');

        $this->seedCart([
            $this->stockFor($a),
            $this->stockFor($b, 30.00),
            $this->stockFor($c, 20.00),
        ]);

        $response = $this->checkout()->assertStatus(422);

        $message = (string) $response->json('errors.pharmacy_distance.0');

        // الرسالة يجب أن تسمّي الطرفين الأبعدين لا الجارين.
        $this->assertStringContainsString('صيدلية أ', $message);
        $this->assertStringContainsString('صيدلية ج', $message);
        $this->assertStringContainsString('700', $message);
    }

    // ── صيدلية واحدة ─────────────────────────────────────────────────────

    public function test_single_pharmacy_checkout_skips_the_proximity_check(): void
    {
        // إحداثيات ناقصة عمداً: صيدلية واحدة لا تدخل أي مقارنة، فلا تُعاقب.
        $only = $this->pharmacyWithoutCoordinates('صيدلية وحيدة');

        $this->seedCart([$this->stockFor($only)]);

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.sub_orders_count', 1);
    }

    public function test_single_pharmacy_with_valid_coordinates_is_unaffected(): void
    {
        $only = $this->pharmacyAt(0, 'صيدلية وحيدة');

        $this->seedCart([$this->stockFor($only)]);

        $this->checkout()
            ->assertStatus(201)
            ->assertJsonPath('data.sub_orders_count', 1);
    }

    // ── الإحداثيات المفقودة / غير الصالحة ────────────────────────────────

    public function test_multi_pharmacy_order_with_missing_coordinates_is_rejected(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyWithoutCoordinates('صيدلية بلا إحداثيات');

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        $response = $this->checkout()->assertStatus(422);

        $response->assertJsonValidationErrors('pharmacy_distance');

        $message = (string) $response->json('errors.pharmacy_distance.0');

        $this->assertStringContainsString('صيدلية بلا إحداثيات', $message);
        $this->assertStringContainsString('إحداثيات', $message);
    }

    public function test_multi_pharmacy_order_with_out_of_range_coordinates_is_rejected(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');

        // decimal(10,8) يقبل 95 رقمياً لكنه ليس خط عرض صالحاً.
        $b = Pharmacy::factory()->create([
            'is_active' => true,
            'pharmacy_name' => 'صيدلية ب',
            'latitude' => 95.0,
            'longitude' => self::BASE_LNG,
        ]);

        $this->seedCart([$this->stockFor($a), $this->stockFor($b, 30.00)]);

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonValidationErrors('pharmacy_distance');
    }

    // ── عدم ترك أثر ──────────────────────────────────────────────────────

    public function test_rejected_checkout_leaves_no_order_items_or_stock_changes(): void
    {
        $a = $this->pharmacyAt(0, 'صيدلية أ');
        $b = $this->pharmacyAt(620, 'صيدلية ب');

        $pmA = $this->stockFor($a);
        $pmB = $this->stockFor($b, 30.00);

        $stockA = (int) $pmA->quantity;
        $stockB = (int) $pmB->quantity;

        $cart = $this->seedCart([$pmA, $pmB]);

        $this->checkout()->assertStatus(422);

        // لا طلب، لا طلب فرعي، لا بند.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, SubOrder::count());
        $this->assertSame(0, OrderItem::count());

        // المخزون كما كان — لم يُخصم شيء.
        $this->assertSame($stockA, (int) $pmA->fresh()->quantity);
        $this->assertSame($stockB, (int) $pmB->fresh()->quantity);

        // السلة لم تُفرَّغ: التفريغ يحدث بعد الـtransaction فقط.
        $this->assertSame(2, $cart->items()->count());
    }

    // ── عدم تأثر البحث القريب ────────────────────────────────────────────

    public function test_pharmacy_search_radius_is_unaffected_by_the_checkout_rule(): void
    {
        $this->pharmacyAt(0, 'صيدلية أ');
        $this->pharmacyAt(400, 'صيدلية ب');

        $response = $this->getJson(
            '/api/pharmacies?latitude='.self::BASE_LAT.'&longitude='.self::BASE_LNG.'&radius_km=5'
        );

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertGreaterThanOrEqual(
            2,
            count((array) $response->json('data')),
            'البحث بنصف القطر يجب أن يبقى يعمل كما كان'
        );
    }
}
