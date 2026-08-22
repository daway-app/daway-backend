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
        Notification::create([
            'user_id' => $pharmacy->user->id,
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
