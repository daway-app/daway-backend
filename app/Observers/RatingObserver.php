<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\Rating;

class RatingObserver
{
    public function created(Rating $rating): void
    {
        $pharmacy = $rating->pharmacy;
        if (! $pharmacy || ! $pharmacy->user) {
            return;
        }

        // H-6: إعادة حساب المعدل المجمّع بعد كل تقييم — العمود يُقرأ من
        // contract الجوال (pharmacyRowPayload/payload) وكان ميتاً بدون هذه الصيانة
        $pharmacy->forceFill([
            'avg_rating' => round((float) $pharmacy->ratings()->avg('stars_rating'), 2),
        ])->save();

        $userId = $pharmacy->user->id;

        // C7: لا تُنشئ إشعار تقييم جديد إن وُجد إشعار تقييم سابق غير مقروء
        // (يمنع spam من نفس الـ user لو قيّم عدة مرات — يحدث مع updateOrCreate).
        $existing = Notification::where('user_id', $userId)
            ->where('type', 'new_rating')
            ->where('is_read', false)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->first();

        if ($existing) {
            $existing->update([
                'message' => __('layout.notif_new_rating', [
                    'stars' => $rating->stars_rating,
                    'name' => $pharmacy->pharmacy_name,
                ]),
                'created_at' => now(),
            ]);

            return;
        }

        Notification::create([
            'user_id' => $userId,
            'medicine_id' => null,
            'type' => 'new_rating',
            'message' => __('layout.notif_new_rating', [
                'stars' => $rating->stars_rating,
                'name' => $pharmacy->pharmacy_name,
            ]),
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
