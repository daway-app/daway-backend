<?php

namespace App\Support;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\PharmacyMedicine;
use Throwable;

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
            // C7 dedup: تحديث إشعار قائم — لا push إضافي (منع duplicate push)
            $existing->update(['created_at' => now()]);

            return;
        }

        $notification = Notification::create([
            'user_id' => $userId,
            'medicine_id' => $medicineId,
            'type' => $type,
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);

        // FCM push فقط عند إنشاء إشعار جديد — بعد نجاح الإنشاء
        app(FcmSender::class)->fromNotification($notification);
    }

    /**
     * إشعار جماعي لمجموعة أصناف مخزون تغيّرت كمياتها — مُخصَّص لمسار
     * المحاسبة (`AccountingLedger::recordSale`) بعد نجاح المعاملة.
     *
     * يعيد استخدام **نفس** منطق `notifyIfLowStock`/`upsertNotification`:
     * نفس الحد (`LOW_STOCK_THRESHOLD`)، نفس تصنيف `low_stock`/`out_of_stock`
     * (M-2.4)، ونفس التفريد C7 ⇒ لا صفوف مكررة ولا push مكرر لاستدعاءات
     * متتالية على نفس الدواء.
     *
     * يُستدعى **بعد commit** دائمًا — فشل أي إشعار هنا يُسجَّل ولا يُسقط
     * العملية المحاسبية التي تمت بالفعل.
     *
     * @param  array<int,int>  $pmIds  معرّفات `pharmacy_medicines` المتأثرة
     */
    public static function notifyForMany(int $pharmacyId, array $pmIds): void
    {
        $pmIds = array_values(array_unique(array_filter(array_map('intval', $pmIds))));

        if ($pmIds === []) {
            return;
        }

        $rows = PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('id', $pmIds)
            ->with('medicine', 'pharmacy.user')
            ->get();

        foreach ($rows as $pm) {
            try {
                self::notifyIfLowStock($pm);
            } catch (Throwable $e) {
                // فشل إشعار صنف واحد لا يمنع إشعار الأصناف الأخرى
                report($e);
            }
        }
    }
}
