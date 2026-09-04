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
     *  لا نُرسل إشعار عند مجرد تعديل كمية دواء متوفر أصلاً (is_available=true).
     *  فقط عندما ينتقل الحقل is_available من false إلى true (انتقال صريح للحالة):
     *      old.is_available === false
     *      new.is_available === true && quantity > 0
     *
     *  ملاحظة: كمية صفر مع is_available=true (مثل data-entry خاطئ) لا تُعتبر "غير متوفر"
     *  لأن الفلاغ الصريح يقول إن الدواء متاح. الزيادة في quantity لا تطلق إشعاراً.
     */
    public function updated(PharmacyMedicine $pm): void
    {
        $oldIsAvailable = $pm->getOriginal('is_available');
        $newIsAvailable = $pm->is_available;

        // لا نُفعّل إلا إذا تغيّر الحقل من false إلى true.
        if ($oldIsAvailable === $newIsAvailable) {
            return;
        }

        // 2) الحالة النهائية يجب أن تكون "متوفر" (is_available=true وكمية > 0).
        $nowAvailable = $newIsAvailable === true && (int) $pm->quantity > 0;

        // 3) الحالة السابقة يجب أن تكون "غير متوفر" (is_available=false).
        $wasUnavailable = $oldIsAvailable === false || $oldIsAvailable === 0 || $oldIsAvailable === null;

        // شرط الانتقال الحقيقي: is_available false -> true.
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