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

        // الثلاثاء 13:00 UTC — لكن اليوم يُشتق من $now، فنمرر يوم الأحد نفسه
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

        // النسخة المكررة القديمة كانت تفشل هنا (parse(null))
        $this->assertFalse(PharmacyAvailability::isOpenNow($pharmacy, Carbon::parse('next sunday 13:00')));
    }
}
