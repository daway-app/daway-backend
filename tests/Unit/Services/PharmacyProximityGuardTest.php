<?php

namespace Tests\Unit\Services;

use App\Models\Pharmacy;
use App\Services\PharmacyProximityGuard;
use PHPUnit\Framework\TestCase;

/**
 * تثبيت رياضيات بوابة التقارب وحدودها بدقة — بلا قاعدة بيانات.
 *
 * الاختبارات هنا تحرس ثلاث نقاط لا تظهر جيداً في اختبارات HTTP:
 *  1. الحد عند 500 بالضبط (مسموح) و501 (مرفوض) — لا "تقريب كسول".
 *  2. "أبعد زوج" لا "الأزواج المتجاورة".
 *  3. التكرار (نفس الصيدلية عدة أسطر) لا يُحسب صيدليتين.
 */
class PharmacyProximityGuardTest extends TestCase
{
    /** متر لكل درجة عرض عند نصف قطر 6371 كم — نفس ثابت Haversine. */
    private const METERS_PER_DEGREE_LAT = 111_194.9266;

    private const BASE_LAT = 31.5;
    private const BASE_LNG = 34.47;

    private function pharmacy(int $id, float $northMeters, ?float $latitude = null, ?float $longitude = null): Pharmacy
    {
        $pharmacy = new Pharmacy();
        $pharmacy->id = $id;
        $pharmacy->pharmacy_name = 'P'.$id;
        $pharmacy->latitude = $latitude ?? (self::BASE_LAT + ($northMeters / self::METERS_PER_DEGREE_LAT));
        $pharmacy->longitude = $longitude ?? self::BASE_LNG;

        return $pharmacy;
    }

    private function withoutCoordinates(int $id): Pharmacy
    {
        $pharmacy = new Pharmacy();
        $pharmacy->id = $id;
        $pharmacy->pharmacy_name = 'P'.$id;
        $pharmacy->latitude = null;
        $pharmacy->longitude = null;

        return $pharmacy;
    }

    // ── الحساب ───────────────────────────────────────────────────────────

    public function test_distance_helper_returns_unrounded_meters(): void
    {
        // الدالة تُعيد قيمة خاماً (float) لا متراً صحيحاً — لأن القرار يُبنى عليها.
        $this->assertEqualsWithDelta(
            350.0,
            PharmacyProximityGuard::distanceMeters($this->pharmacy(1, 0), $this->pharmacy(2, 350)),
            0.01
        );

        $this->assertEqualsWithDelta(
            500.0,
            PharmacyProximityGuard::distanceMeters($this->pharmacy(1, 0), $this->pharmacy(2, 500)),
            0.01
        );
    }

    // ── الحد ─────────────────────────────────────────────────────────────

    public function test_exactly_500_meters_is_allowed(): void
    {
        $this->assertNull(
            PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 500)]),
            'المسافة 500 متر مسموحة — الرفض يبدأ من 501'
        );
    }

    public function test_501_meters_is_rejected(): void
    {
        $violation = PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 501)]);

        $this->assertNotNull($violation);
        $this->assertSame('too_far', $violation['reason']);
        $this->assertEqualsWithDelta(501.0, $violation['distance_meters'], 0.01);
        $this->assertSame([1, 2], array_column($violation['pharmacies'], 'id'));
    }

    /**
     * كان `(int) round($km * 1000)` يقبل 500.1 و500.4 (لأنهما يُقرَّبان إلى
     * 500). الحد الآن صارم: أي مسافة فوق 500 م تُرفض.
     */
    public function test_500_1_meters_is_rejected(): void
    {
        $violation = PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 500.1)]);

        $this->assertNotNull($violation, '500.1 م أكبر من 500 ويجب رفضها');
        $this->assertSame('too_far', $violation['reason']);
        $this->assertEqualsWithDelta(500.1, $violation['distance_meters'], 0.01);
    }

    public function test_500_4_meters_is_rejected(): void
    {
        $violation = PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 500.4)]);

        $this->assertNotNull($violation, '500.4 م كانت تُقبل سابقاً بالتقريب — يجب رفضها الآن');
        $this->assertSame('too_far', $violation['reason']);
        $this->assertEqualsWithDelta(500.4, $violation['distance_meters'], 0.01);
    }

    /**
     * الهامش العددي 1 ملّيمتر فقط — لا يتسرّب إلى السنتيمتر.
     * 500.01 م (سنتيمتر واحد فوق الحد) يجب أن تُرفض.
     */
    public function test_epsilon_does_not_leak_into_centimetres(): void
    {
        $violation = PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 500.01)]);

        $this->assertNotNull($violation, 'الهامش 1 مم لا يجوز أن يسمح بفارق سنتيمتر');
        $this->assertSame('too_far', $violation['reason']);
    }

    public function test_under_the_limit_is_allowed(): void
    {
        $this->assertNull(
            PharmacyProximityGuard::inspect([$this->pharmacy(1, 0), $this->pharmacy(2, 120)])
        );
    }

    // ── صيدلية واحدة / تكرار ─────────────────────────────────────────────

    public function test_single_pharmacy_is_always_allowed(): void
    {
        $this->assertNull(PharmacyProximityGuard::inspect([$this->pharmacy(1, 0)]));
    }

    public function test_single_pharmacy_without_coordinates_is_allowed(): void
    {
        // لا زوج ⇒ لا مقارنة ⇒ لا عقوبة على إحداثيات ناقصة.
        $this->assertNull(PharmacyProximityGuard::inspect([$this->withoutCoordinates(1)]));
    }

    public function test_duplicate_rows_of_the_same_pharmacy_count_once(): void
    {
        $a = $this->pharmacy(1, 0);

        $this->assertNull(
            PharmacyProximityGuard::inspect([$a, $a, $a]),
            'عدة أسطر من نفس الصيدلية ليست عدة صيدليات'
        );
    }

    // ── أبعد زوج ─────────────────────────────────────────────────────────

    public function test_farthest_pair_wins_over_adjacent_pairs(): void
    {
        // A=0 · B=350 · C=700 — كل زوج متجاور 350 فقط، لكن A↔C = 700.
        $violation = PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->pharmacy(2, 350),
            $this->pharmacy(3, 700),
        ]);

        $this->assertNotNull($violation, 'السلسلة يجب أن تُرفض لأن طرفيها متباعدان');
        $this->assertSame('too_far', $violation['reason']);
        $this->assertEqualsWithDelta(700.0, $violation['distance_meters'], 0.01);
        $this->assertSame([1, 3], array_column($violation['pharmacies'], 'id'), 'الزوج المُبلَّغ هو الأبعد');
    }

    public function test_three_close_pharmacies_are_allowed(): void
    {
        $this->assertNull(PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->pharmacy(2, 200),
            $this->pharmacy(3, 450),
        ]));
    }

    // ── الإحداثيات المفقودة / غير الصالحة ────────────────────────────────

    public function test_missing_coordinates_are_rejected_for_multi_pharmacy(): void
    {
        $violation = PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->withoutCoordinates(2),
        ]);

        $this->assertNotNull($violation);
        $this->assertSame('missing_coordinates', $violation['reason']);
        $this->assertSame([2], array_column($violation['pharmacies'], 'id'));
    }

    public function test_out_of_range_latitude_is_treated_as_unusable(): void
    {
        // العمود decimal(10,8) يقبل 95 رقمياً، لكنه ليس خط عرض صالحاً.
        $violation = PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->pharmacy(2, 0, 95.0, self::BASE_LNG),
        ]);

        $this->assertNotNull($violation);
        $this->assertSame('missing_coordinates', $violation['reason']);
    }

    public function test_out_of_range_longitude_is_treated_as_unusable(): void
    {
        $violation = PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->pharmacy(2, 0, self::BASE_LAT, 181.0),
        ]);

        $this->assertNotNull($violation);
        $this->assertSame('missing_coordinates', $violation['reason']);
    }

    public function test_message_names_both_pharmacies_and_the_distance(): void
    {
        $violation = PharmacyProximityGuard::inspect([
            $this->pharmacy(1, 0),
            $this->pharmacy(2, 620),
        ]);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('P1', $violation['message']);
        $this->assertStringContainsString('P2', $violation['message']);
        $this->assertStringContainsString('620', $violation['message']);
        $this->assertStringContainsString('500', $violation['message']);
    }
}
