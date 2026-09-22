<?php

namespace Tests\Unit;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Support\PharmacyAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H-14/M-8: isOpenNow يُقرأ من عقد الجوال (is_open_now) — كان بصفر اختبارات.
 * الأيام بأسماء إنجليزية كاملة (day_of_week).
 *
 * Phase 14: إضافة اختبارات overnight hours + open_now filter + timezone.
 */
class PharmacyAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacyWithHours(string $dayName, string $open, string $close, bool $closed = false): Pharmacy
    {
        $pharmacy = Pharmacy::factory()->create();
        PharmacyHour::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => $dayName,
            'open_time' => $open,
            'close_time' => $close,
            'is_closed' => $closed,
        ]);

        return $pharmacy;
    }

    public function test_open_during_business_hours(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '08:00', '22:00');

        $now = Carbon::parse('next sunday 13:00');

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_closed_outside_hours(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '08:00', '10:00');

        $now = Carbon::parse('next sunday 23:00');

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_closed_day_flag_returns_false(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '08:00', '22:00', closed: true);

        $now = Carbon::parse('next sunday 13:00');

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_no_hours_row_returns_false(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, Carbon::parse('next sunday 13:00')));
    }

    public function test_null_times_are_guarded_not_parsed(): void
    {
        $pharmacy = Pharmacy::factory()->create();
        PharmacyHour::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'day_of_week' => 'Sunday',
            'open_time' => null,
            'close_time' => null,
            'is_closed' => false,
        ]);

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, Carbon::parse('next sunday 13:00')));
    }

    // ===== Phase 14: Overnight Hours =====

    public function test_overnight_open_before_midnight(): void
    {
        // 22:00 → 02:00
        $pharmacy = $this->pharmacyWithHours('Sunday', '22:00', '02:00');

        // 23:00 — between 22:00 and 02:00 (next day)
        $now = Carbon::parse('next sunday 23:00');

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_overnight_open_after_midnight(): void
    {
        // 22:00 → 02:00
        $pharmacy = $this->pharmacyWithHours('Sunday', '22:00', '02:00');

        // 01:00 — between 22:00 (yesterday) and 02:00 (today)
        $now = Carbon::parse('next monday 01:00');

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_overnight_closed_outside_range(): void
    {
        // 22:00 → 02:00
        $pharmacy = $this->pharmacyWithHours('Sunday', '22:00', '02:00');

        // 10:00 — outside overnight hours
        $now = Carbon::parse('next monday 10:00');

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_exact_opening_boundary_is_open(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '09:00', '17:00');

        $now = Carbon::parse('next sunday 09:00');

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_exact_closing_boundary_is_open(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '09:00', '17:00');

        $now = Carbon::parse('next sunday 17:00');

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    public function test_one_minute_after_closing_is_closed(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '09:00', '17:00');

        $now = Carbon::parse('next sunday 17:01');

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }

    // ===== Phase 14: Timezone =====

    public function test_open_now_uses_app_timezone(): void
    {
        $pharmacy = $this->pharmacyWithHours('Sunday', '08:00', '22:00');

        // Use a Sunday with known time
        $now = Carbon::parse('next sunday 13:00', 'UTC');
        Carbon::setTestNow($now);

        $this->assertTrue(PharmacyAvailability::isOpenNow($pharmacy));
    }

    // ===== Phase 14: Opening == Closing =====

    public function test_same_open_close_time_treated_as_never_open(): void
    {
        // Per project convention: opening_time == closing_time = closed (24h not assumed)
        $pharmacy = $this->pharmacyWithHours('Sunday', '12:00', '12:00');

        $now = Carbon::parse('next sunday 12:00');

        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, $now));
    }
}
