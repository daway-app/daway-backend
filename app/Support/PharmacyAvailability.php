<?php

namespace App\Support;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use Carbon\Carbon;

class PharmacyAvailability
{
    /**
     * هل الصيدلية مفتوحة الآن؟ يفحص يوم + وقت مقارنة بـ pharmacy_hours.
     * يدعم overnight hours (مثلاً 22:00 → 02:00).
     */
    public static function isOpenNow(Pharmacy $pharmacy, ?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return self::checkDay($pharmacy, $now)
            || self::checkPreviousDay($pharmacy, $now);
    }

    private static function checkDay(Pharmacy $pharmacy, Carbon $now): bool
    {
        $dayName = $now->format('l');

        $hour = $pharmacy->hours->firstWhere('day_of_week', $dayName);

        if (! $hour || empty($hour->open_time) || empty($hour->close_time) || $hour->is_closed) {
            return false;
        }

        $openTime = $hour->open_time->format('H:i');
        $closeTime = $hour->close_time->format('H:i');

        if ($openTime === $closeTime) {
            return false;
        }

        $open = Carbon::parse($openTime)->setDateFrom($now);
        $close = Carbon::parse($closeTime)->setDateFrom($now);

        if ($open->gt($close)) {
            // Overnight: open_time > close_time (e.g., 22:00 → 02:00)
            // Open from open_time today until close_time tomorrow
            return $now->between($open, $close->addDay());
        }

        return $now->between($open, $close);
    }

    private static function checkPreviousDay(Pharmacy $pharmacy, Carbon $now): bool
    {
        $prevDayName = $now->copy()->subDay()->format('l');

        $hour = $pharmacy->hours->firstWhere('day_of_week', $prevDayName);

        if (! $hour || empty($hour->open_time) || empty($hour->close_time) || $hour->is_closed) {
            return false;
        }

        $openTime = $hour->open_time->format('H:i');
        $closeTime = $hour->close_time->format('H:i');

        // Only previous day's overnight hours can extend into current day
        if ($openTime <= $closeTime) {
            return false;
        }

        // Overnight: previous day open_time > close_time (e.g., 22:00 → 02:00)
        // Open from previous day's open_time until today's close_time
        $open = Carbon::parse($openTime)->setDateFrom($now->copy()->subDay());
        $close = Carbon::parse($closeTime)->setDateFrom($now);

        return $now->between($open, $close);
    }
}
