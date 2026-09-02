<?php

namespace App\Support;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use Carbon\Carbon;

class PharmacyAvailability
{
    /**
     * هل الصيدلية مفتوحة الآن؟ يفحص يوم + وقت مقارنة بـ pharmacy_hours.
     * يستخدم columns: day_of_week (full name) + open_time + close_time.
     */
    public static function isOpenNow(Pharmacy $pharmacy, ?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();
        $dayName = $now->format('l');   // Sunday, Monday, ...

        $hour = $pharmacy->hours->firstWhere('day_of_week', $dayName);

        if (! $hour || empty($hour->open_time) || empty($hour->close_time) || $hour->is_closed) {
            return false;
        }

        $open = Carbon::parse($hour->open_time)->setDateFrom($now);
        $close = Carbon::parse($hour->close_time)->setDateFrom($now);

        return $now->between($open, $close);
    }
}
