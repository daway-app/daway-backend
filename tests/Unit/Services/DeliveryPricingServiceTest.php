<?php

namespace Tests\Unit\Services;

use App\Models\Address;
use App\Models\Pharmacy;
use App\Services\DeliveryPricingService;
use App\Support\Haversine;
use PHPUnit\Framework\TestCase;

/**
 * تثبيت منطق تسعير التوصيل النقي — بلا قاعدة بيانات.
 *
 * يحرس هذا الاختبار القاعدتين الأكثر عرضة للكسر الصامت:
 *   1. قواعد مجموعة الشرائح الفعّالة (بلا تداخل/فجوات، تبدأ من 0، المفتوحة أخيرًا).
 *   2. أن المسافة **بخط مستقيم** بالأمتار، وأن غياب الإحداثيات يُعيد null
 *      (لا قيمة مصطنعة).
 *
 * الجزء المرتبط بقاعدة البيانات (الشرائح المخزّنة، مفتاح التفعيل) مغطّى في
 * اختباري الـFeature للأدمن وللـcheckout.
 */
class DeliveryPricingServiceTest extends TestCase
{
    /** متر لكل درجة عرض عند نصف قطر 6371 كم — نفس ثابت Haversine. */
    private const METERS_PER_DEGREE_LAT = 111_194.9266;

    private const BASE_LAT = 31.5;
    private const BASE_LNG = 34.47;

    private function tier(int $min, ?int $max, float $fee = 10.0): array
    {
        return ['min_distance_meters' => $min, 'max_distance_meters' => $max, 'fee' => $fee];
    }

    private function pharmacy(?float $lat, ?float $lng): Pharmacy
    {
        $pharmacy = new Pharmacy();
        $pharmacy->latitude = $lat;
        $pharmacy->longitude = $lng;

        return $pharmacy;
    }

    private function address(?float $lat, ?float $lng): Address
    {
        $address = new Address();
        $address->latitude = $lat;
        $address->longitude = $lng;

        return $address;
    }

    // ── مجموعة الشرائح الفعّالة ───────────────────────────────────────────

    public function test_a_contiguous_set_starting_at_zero_is_valid(): void
    {
        $this->assertSame([], DeliveryPricingService::validateActiveSet([
            $this->tier(0, 1000),
            $this->tier(1001, 2000),
            $this->tier(2001, null),
        ]));
    }

    public function test_a_single_open_ended_tier_is_valid(): void
    {
        $this->assertSame([], DeliveryPricingService::validateActiveSet([$this->tier(0, null)]));
    }

    public function test_an_empty_set_is_valid(): void
    {
        $this->assertSame([], DeliveryPricingService::validateActiveSet([]));
    }

    public function test_the_set_must_start_at_zero(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([$this->tier(100, null)]);

        $this->assertNotEmpty($errors);
    }

    public function test_overlapping_tiers_are_rejected(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([
            $this->tier(0, 1000),
            $this->tier(900, null),
        ]);

        $this->assertNotEmpty($errors);
    }

    public function test_a_gap_between_tiers_is_rejected(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([
            $this->tier(0, 1000),
            $this->tier(1002, null),
        ]);

        $this->assertNotEmpty($errors);
    }

    public function test_an_open_ended_tier_must_be_last(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([
            $this->tier(0, null),
            $this->tier(1000, 2000),
        ]);

        $this->assertNotEmpty($errors);
    }

    public function test_a_negative_fee_is_rejected(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([$this->tier(0, null, -1.0)]);

        $this->assertNotEmpty($errors);
    }

    public function test_a_negative_minimum_distance_is_rejected(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([$this->tier(-5, null)]);

        $this->assertNotEmpty($errors);
    }

    public function test_max_below_min_is_rejected(): void
    {
        $errors = DeliveryPricingService::validateActiveSet([$this->tier(1000, 500)]);

        $this->assertNotEmpty($errors);
    }

    // ── المسافة ────────────────────────────────────────────────────────────

    public function test_distance_is_zero_for_identical_coordinates(): void
    {
        $this->assertSame(0, DeliveryPricingService::distanceMeters(
            $this->pharmacy(self::BASE_LAT, self::BASE_LNG),
            $this->address(self::BASE_LAT, self::BASE_LNG),
        ));
    }

    public function test_distance_is_measured_in_straight_line_meters(): void
    {
        $north = self::BASE_LAT + (1000.0 / self::METERS_PER_DEGREE_LAT);

        $meters = DeliveryPricingService::distanceMeters(
            $this->pharmacy(self::BASE_LAT, self::BASE_LNG),
            $this->address($north, self::BASE_LNG),
        );

        $this->assertEqualsWithDelta(1000, $meters, 2);
    }

    public function test_distance_returns_null_when_pharmacy_coordinates_are_missing(): void
    {
        $this->assertNull(DeliveryPricingService::distanceMeters(
            $this->pharmacy(null, null),
            $this->address(self::BASE_LAT, self::BASE_LNG),
        ));
    }

    public function test_distance_returns_null_when_address_coordinates_are_missing(): void
    {
        $this->assertNull(DeliveryPricingService::distanceMeters(
            $this->pharmacy(self::BASE_LAT, self::BASE_LNG),
            $this->address(null, null),
        ));
    }

    public function test_distance_returns_null_for_out_of_range_coordinates(): void
    {
        $this->assertNull(DeliveryPricingService::distanceMeters(
            $this->pharmacy(95.0, 34.47), // خط عرض خارج النطاق الجغرافي
            $this->address(self::BASE_LAT, self::BASE_LNG),
        ));
    }

    // ── المسار المفتوح متعدد الصيدليات (TSP) ─────────────────────────────────

    /** إحداثيات مُعطاة كـ[خط العرض, خط الطول] بإزاحة بالمتر عن نقطة الأساس. */
    private function pt(float $northMeters, float $eastMeters): array
    {
        return [
            self::BASE_LAT + ($northMeters / self::METERS_PER_DEGREE_LAT),
            self::BASE_LNG + ($eastMeters / self::METERS_PER_DEGREE_LAT),
        ];
    }

    private function meters(array $a, array $b): float
    {
        return Haversine::kmBetween($a[0], $a[1], $b[0], $b[1]) * 1000.0;
    }

    /**
     * حَل مرجعي بالتفجير العاملي: كل ترتيبات نقاط الاستلام، ثم الوجهة ثابتة في
     * النهاية. هو «الحل الأمثل» الذي يقارن به اختبار الدقة.
     *
     * @param  array<int, array{0: float, 1: float}>  $pickups
     */
    private function bruteForce(array $pickups, array $destination): float
    {
        $n = count($pickups);

        if ($n === 0) {
            return 0.0;
        }

        $best = null;

        foreach ($this->permutations(range(0, $n - 1)) as $perm) {
            $cost = 0.0;
            for ($i = 0; $i < $n - 1; $i++) {
                $cost += $this->meters($pickups[$perm[$i]], $pickups[$perm[$i + 1]]);
            }
            $cost += $this->meters($pickups[$perm[$n - 1]], $destination);

            if ($best === null || $cost < $best) {
                $best = $cost;
            }
        }

        return $best ?? 0.0;
    }

    /** @param  array<int, int>  $items */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $tail) {
                array_unshift($tail, $item);
                $result[] = $tail;
            }
        }

        return $result;
    }

    public function test_open_path_without_pickups_is_zero(): void
    {
        $this->assertSame(0.0, DeliveryPricingService::shortestOpenPathMeters(
            [],
            $this->pt(1500, 0),
        ));
    }

    public function test_open_path_with_one_pickup_is_the_direct_distance(): void
    {
        $this->assertEqualsWithDelta(
            1500,
            DeliveryPricingService::shortestOpenPathMeters([$this->pt(0, 0)], $this->pt(1500, 0)),
            2,
        );
    }

    public function test_open_path_picks_the_shorter_ordering_and_has_no_return_leg(): void
    {
        // A في الأساس، B على 300م شمالًا، الوجهة 1500م شمالًا عن A.
        // الأفضل: A → B → الوجهة = 300 + 1200 = 1500 (لا 300 + 1500، ولا رجوع).
        $a = $this->pt(0, 0);
        $b = $this->pt(300, 0);
        $dest = $this->pt(1500, 0);

        $this->assertEqualsWithDelta(
            1500,
            DeliveryPricingService::shortestOpenPathMeters([$a, $b], $dest),
            2,
        );
    }

    public function test_open_path_matches_the_brute_force_optimum_for_small_sets(): void
    {
        $pickups = [
            $this->pt(100, 100),
            $this->pt(300, -200),
            $this->pt(-150, 400),
            $this->pt(250, 50),
            $this->pt(-50, -300),
            $this->pt(400, 350),
        ];
        $dest = $this->pt(600, 250);

        $this->assertEqualsWithDelta(
            $this->bruteForce($pickups, $dest),
            DeliveryPricingService::shortestOpenPathMeters($pickups, $dest),
            1.0e-6,
        );
    }

    public function test_open_path_fallback_stays_within_a_valid_upper_bound_for_large_sets(): void
    {
        // 13 نقطة ⇒ يتجاوز حد الحساب الدقيق، فيُستخدم التقريب حتميًا.
        $pickups = [];
        for ($i = 0; $i < 13; $i++) {
            $pickups[] = $this->pt(($i % 5) * 90 - 180, intdiv($i, 5) * 120 - 120);
        }
        $dest = $this->pt(500, 500);

        $result = DeliveryPricingService::shortestOpenPathMeters($pickups, $dest);

        // حدّ أعلى صالح: تكلفة الترتيب كما أُدخل (لا يمكن للتقريب أن يزيد عنها).
        $sequential = 0.0;
        for ($i = 0; $i < count($pickups) - 1; $i++) {
            $sequential += $this->meters($pickups[$i], $pickups[$i + 1]);
        }
        $sequential += $this->meters($pickups[count($pickups) - 1], $dest);

        $this->assertGreaterThan(0.0, $result);
        $this->assertLessThanOrEqual($sequential + 1.0e-6, $result);
    }

    public function test_open_path_is_exact_at_the_threshold_and_remains_correct_just_above_it(): void
    {
        // نقاط على خط واحد تتزايد شمالًا والوجهة أبعد شمالًا منها جميعًا: عندئذٍ
        // أقصر مسار مفتوح معروف تحليليًا = المسافة من أدنى نقطة إلى الوجهة (يمرّ
        // بالباقي تصاعديًا). نفحص الحدّ نفسه (12 = مسار دقيق) وفوقه (13 = تقريب).
        $destination = $this->pt(5000, 0);
        $expected = $this->meters($this->pt(0, 0), $destination);

        $twelve = [];
        for ($i = 0; $i < 12; $i++) {
            $twelve[] = $this->pt($i * 100, 0);
        }
        $this->assertEqualsWithDelta(
            $expected,
            DeliveryPricingService::shortestOpenPathMeters($twelve, $destination),
            2.0,
        );

        $thirteen = [];
        for ($i = 0; $i < 13; $i++) {
            $thirteen[] = $this->pt($i * 100, 0);
        }
        $this->assertEqualsWithDelta(
            $expected,
            DeliveryPricingService::shortestOpenPathMeters($thirteen, $destination),
            2.0,
        );
    }

    // ── مسافة الرحلة لطلب متعدد الصيدليات ────────────────────────────────────

    private function pharmacyWithId(int $id, ?float $lat, ?float $lng): Pharmacy
    {
        $pharmacy = new Pharmacy();
        $pharmacy->id = $id;
        $pharmacy->latitude = $lat;
        $pharmacy->longitude = $lng;

        return $pharmacy;
    }

    public function test_route_distance_for_a_single_pharmacy_is_the_direct_distance(): void
    {
        $pharmacies = collect([$this->pharmacyWithId(1, self::BASE_LAT, self::BASE_LNG)]);

        $this->assertEqualsWithDelta(
            1500,
            DeliveryPricingService::routeDistanceMeters($pharmacies, $this->address(
                self::BASE_LAT + (1500.0 / self::METERS_PER_DEGREE_LAT),
                self::BASE_LNG,
            )),
            2,
        );
    }

    public function test_route_distance_for_multiple_pharmacies_is_the_open_path_total(): void
    {
        $pharmacies = collect([
            $this->pharmacyWithId(1, self::BASE_LAT, self::BASE_LNG),
            $this->pharmacyWithId(2, self::BASE_LAT + (300.0 / self::METERS_PER_DEGREE_LAT), self::BASE_LNG),
        ]);

        // A → B (300) + B → الوجهة (1200) = 1500، لا مجموع مسافتين (1500+1200).
        $this->assertEqualsWithDelta(
            1500,
            DeliveryPricingService::routeDistanceMeters($pharmacies, $this->address(
                self::BASE_LAT + (1500.0 / self::METERS_PER_DEGREE_LAT),
                self::BASE_LNG,
            )),
            2,
        );
    }

    public function test_route_distance_is_null_when_any_pharmacy_lacks_coordinates(): void
    {
        $pharmacies = collect([
            $this->pharmacyWithId(1, self::BASE_LAT, self::BASE_LNG),
            $this->pharmacyWithId(2, null, null),
        ]);

        $this->assertNull(DeliveryPricingService::routeDistanceMeters(
            $pharmacies,
            $this->address(self::BASE_LAT, self::BASE_LNG),
        ));
    }

    public function test_route_distance_is_null_when_the_address_lacks_coordinates(): void
    {
        $pharmacies = collect([$this->pharmacyWithId(1, self::BASE_LAT, self::BASE_LNG)]);

        $this->assertNull(DeliveryPricingService::routeDistanceMeters(
            $pharmacies,
            $this->address(null, null),
        ));
    }

    public function test_route_distance_is_null_for_out_of_range_pharmacy_coordinates(): void
    {
        $pharmacies = collect([$this->pharmacyWithId(1, 95.0, 34.47)]);

        $this->assertNull(DeliveryPricingService::routeDistanceMeters(
            $pharmacies,
            $this->address(self::BASE_LAT, self::BASE_LNG),
        ));
    }
}
