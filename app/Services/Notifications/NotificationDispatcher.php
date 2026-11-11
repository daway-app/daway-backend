<?php

namespace App\Services\Notifications;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\User;
use App\Services\Fcm\FcmPushService;
use Throwable;

/**
 * نقطة واحدة لإنشاء الإشعار ودفعه.
 *
 * كانت المعرفة موزّعة: بعض المسارات تنشئ `Notification` ثم تستدعي FCM يدويًا،
 * و`NotificationGenerator` كان ينشئ الإشعار **دون أي push** — فإشعارات نقص
 * المخزون لا تصل إلى الهاتف إطلاقًا. هذه الخدمة تسدّ ذلك.
 *
 * الإنشاء والدفع منفصلان عمدًا: فشل Firebase لا يجوز أن يُسقط السجل، ولا أن
 * يُسقط العملية الأصلية (طلب/استفسار). السجل يبقى، وحالته قابلة لإعادة المحاولة.
 */
class NotificationDispatcher
{
    /**
     * إنشاء إشعار ثم دفعه. يُعيد `null` إن كان `$dedupeKey` موجودًا مسبقًا.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createAndPush(User $user, array $attributes, ?string $dedupeKey = null): ?Notification
    {
        $payload = array_merge([
            'user_id' => $user->id,
            'is_read' => false,
            'created_at' => now(),
        ], $attributes);

        try {
            if ($dedupeKey !== null) {
                $notification = Notification::firstOrCreate(
                    ['dedupe_key' => $dedupeKey],
                    $payload,
                );

                if (! $notification->wasRecentlyCreated) {
                    return null;
                }
            } else {
                $notification = Notification::create($payload);
            }
        } catch (Throwable $e) {
            // لا نُسقط العملية الأصلية بسبب تعذّر تسجيل الإشعار.
            report($e);

            return null;
        }

        $this->push($notification);

        return $notification;
    }

    /** دفع إشعار قائم — مؤجّل إلى ما بعد الـcommit عبر المزيّن. */
    public function push(Notification $notification): void
    {
        try {
            app(FcmSender::class)->fromNotification($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * إعادة محاولة الإشعارات التي فشل إرسالها بالكامل.
     *
     * لا تحتاج Worker دائمًا: تُشغَّل كأمر يدوي أو مجدول. الحد الأقصى
     * للمحاولات يمنع حلقة لا نهائية على توكنات ميتة أو إعداد خاطئ.
     *
     * @return array{retried: int, sent: int, failed: int}
     */
    public function retryFailed(int $limit = 100, int $maxAttempts = 3): array
    {
        $rows = Notification::query()
            ->where('push_status', FcmPushService::STATUS_FAILED)
            ->where('push_attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($rows as $notification) {
            $before = (int) $notification->push_attempts;

            try {
                app(FcmSender::class)->fromNotification($notification);
            } catch (Throwable $e) {
                report($e);
            }

            $notification->refresh();

            // لم تُسجَّل محاولة (مستخدم محذوف مثلًا) — لا نُعيد إلى ما لا نهاية.
            if ((int) $notification->push_attempts === $before) {
                $notification->forceFill([
                    'push_status' => FcmPushService::STATUS_FAILED,
                    'push_attempts' => $maxAttempts,
                ])->saveQuietly();
                $failed++;

                continue;
            }

            $notification->push_status === FcmPushService::STATUS_SENT ? $sent++ : $failed++;
        }

        return ['retried' => $rows->count(), 'sent' => $sent, 'failed' => $failed];
    }
}
