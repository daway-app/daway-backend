<?php

namespace App\Services;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;

/**
 * M-10/M-36: مصدر منطق الرد على الاستفسارات الموحد — API/Web/Sync.
 *
 * السلوك الموحد (كان حصراً بالـ API، والويب والـ sync كانا يحدّثان الحالة فقط):
 *  - reply أو status=answered → replied_at
 *  - answered/reply/availability_status → إشعار للمريض + FCM (توحيد A4-5:
 *    مسار الرد أصبح يرسل push مثل مسار إنشاء الاستفسار)
 */
final class InquiryService
{
    /**
     * @param array{status?: string, reply?: ?string, availability_status?: string} $data
     */
    public function answer(PatientInquiry $inquiry, Pharmacy $pharmacy, array $data): PatientInquiry
    {
        $hasReply = array_key_exists('reply', $data) && $data['reply'] !== null;

        if ($hasReply || (($data['status'] ?? null) === 'answered')) {
            $data['replied_at'] = now();
        }

        $shouldNotify = ($data['status'] ?? null) === 'answered'
            || $hasReply
            || array_key_exists('availability_status', $data);

        $inquiry->update($data);
        $inquiry->load(['user', 'medicine']);

        if ($shouldNotify && $inquiry->user_id) {
            $messageKey = 'layout.notif_inquiry_answered';
            $message = trans()->has($messageKey)
                ? __($messageKey, ['pharmacy' => $pharmacy->pharmacy_name])
                : 'تم الرد على استفسارك من صيدلية '.$pharmacy->pharmacy_name;

            $notification = Notification::create([
                'user_id' => $inquiry->user_id,
                'medicine_id' => $inquiry->medicine_id,
                'type' => 'inquiry_answered',
                'message' => $message,
                'is_read' => false,
                'created_at' => now(),
            ]);

            app(FcmSender::class)->fromNotification($notification);
        }

        return $inquiry;
    }
}
