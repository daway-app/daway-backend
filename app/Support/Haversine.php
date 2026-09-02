<?php

namespace App\Support;

class Haversine
{
    /**
     * المسافة بين نقطتين بالكيلومتر باستخدام صيغة هافرسين.
     * مُستخرج من App\Services\Ai\MedicineResolver::haversineKm لإعادة الاستخدام في طبقة API.
     */
    public static function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }
}
