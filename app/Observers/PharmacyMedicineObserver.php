<?php

namespace App\Observers;

use App\Models\AvailabilityNotification;
use App\Models\Notification;
use App\Models\PharmacyMedicine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PharmacyMedicineObserver
{
    /**
     * C7 / Phase 8: كشف transition حقيقي إلى available لإرسال إشعار إعادة التوفر
     * للمشتركين عبر AvailabilityNotification.
     *
     * القاعدة الصارمة (SRS):
     *  لا نُرسل إشعار عند مجرد تعديل كمية دواء متوفر أصلاً.
     *  فقط عندما ينتقل السطر من حالة "غير متوفر" إلى "متوفر":
     *      old: (quantity <= 0) OR (is_available === false)
     *      new: (quantity >  0) AND (is_available === true)
     */
    public function updated(PharmacyMedicine $pm): void
    {
        // 1) لا نُفعّل المنطق إلا إذا تغيّر أحد الحقلين المؤثرين في التوفر.
        if (! $pm->wasChanged('quantity') && ! $pm->wasChanged('is_available')) {
            return;
        }

        // 2) الحالة النهائية يجب أن تكون "متوفر".
        $nowAvailable = $pm->is_available === true && (int) $pm->quantity > 0;

        // 3) الحالة السابقة يجب أن تكون "غير متوفر".
        $oldQuantity = (int) $pm->getOriginal('quantity');
        $oldIsAvailable = $pm->getOriginal('is_available');
        $wasUnavailable = $oldQuantity <= 0 || $oldIsAvailable === false || $oldIsAvailable === 0 || $oldIsAvailable === null;

        // شرط الانتقال الحقيقي: unavailable -> available.
        if (! ($nowAvailable && $wasUnavailable)) {
            return;
        }

        // 4) جلب كل المشتركين الذين ينتظرون توفر هذا الدواء عند هذه الصيدلية.
        $subscribers = AvailabilityNotification::where('medicine_id', $pm->medicine_id)
            ->where('pharmacy_id', $pm->pharmacy_id)
            ->where('is_notified', false)
            ->get();

        if ($subscribers->isEmpty()) {
            return;
        }

        $medicineName = $pm->medicine?->trade_name_ar ?: $pm->medicine?->trade_name;

        // 5 + 6 + 7: في معاملة واحدة: إنشاء Notification لكل مشترك ثم تعليم الطلب بأنه تمّ الإعلام.
        try {
            DB::transaction(function () use ($subscribers, $pm, $medicineName) {
                foreach ($subscribers as $sub) {
                    Notification::create([
                        'user_id' => $sub->user_id,
                        'medicine_id' => $pm->medicine_id,
                        'type' => 'medicine_available',
                        'message' => $medicineName
                            ? __('layout.notif_medicine_available', ['name' => $medicineName])
                            : 'الدواء أصبح متوفرًا',
                        'is_read' => false,
                        'created_at' => now(),
                    ]);

                    $sub->is_notified = true;
                    $sub->notified_at = now();
                    $sub->save();
                }
            });
        } catch (Throwable $e) {
            // 8) فشل الجانب الإشعاري لا يُسقط التعديل الأساسي للسطر نفسه.
            Log::warning('restock notification failed', [
                'pharmacy_medicine_id' => $pm->id,
                'pharmacy_id' => $pm->pharmacy_id,
                'medicine_id' => $pm->medicine_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}