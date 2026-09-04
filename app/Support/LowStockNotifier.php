<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\PharmacyMedicine;

/**
 * إشعار نقص/نفاد المخزون — منطق مشترك بين لوحة التحكم والـ API.
 *
 * C7: منع الإشعارات المكررة — إن وُجد إشعار غير مقروء من نفس النوع
 * لنفس الدواء، نُحدّث created_at بدل إنشاء إشعار جديد.
 */
class LowStockNotifier
{
    /**
     * فحص كمية الدواء وإطلاق إشعار نقص/نفاد عند الحاجة.
     */
    public static function notifyIfLowStock(PharmacyMedicine $pm): void
    {
        $threshold = PharmacyMedicine::LOW_STOCK_THRESHOLD;
        $pharmacyUser = $pm->pharmacy?->user;
        if (! $pharmacyUser) {
            return;
        }
        if ($pm->quantity <= 0) {
            self::upsertNotification(
                $pharmacyUser->id,
                $pm->medicine_id,
                'out_of_stock',
                __('layout.notif_out_of_stock', ['name' => $pm->medicine?->trade_name])
            );

            return;
        }
        if ($pm->quantity > 0 && $pm->quantity <= $threshold) {
            self::upsertNotification(
                $pharmacyUser->id,
                $pm->medicine_id,
                'low_stock',
                __('layout.notif_low_stock_pharmacy', [
                    'name' => $pm->medicine?->trade_name,
                    'count' => $pm->quantity,
                ])
            );
        }
    }

    /**
     * إنشاء/تحديث إشعار: إن وُجد إشعار غير مقروء من نفس النوع/الدواء/المستخدم،
     * نُجدّد created_at بدل إدراج صف جديد. C7.
     */
    public static function upsertNotification(int $userId, int $medicineId, string $type, string $message): void
    {
        $existing = Notification::where('user_id', $userId)
            ->where('medicine_id', $medicineId)
            ->where('type', $type)
            ->where('is_read', false)
            ->first();

        if ($existing) {
            $existing->update(['created_at' => now()]);

            return;
        }

        Notification::create([
            'user_id' => $userId,
            'medicine_id' => $medicineId,
            'type' => $type,
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
