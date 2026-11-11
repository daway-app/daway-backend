<?php

namespace App\Services\Notifications;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * يُؤجّل كل إرسال إلى **بعد تثبيت المعاملة**.
 *
 * السبب: الإشعار يُنشأ داخل معاملة (الطلب، الاستفسار، المخزون). الإرسال قبل
 * الـcommit يعني أن Firebase قد يُظهر للمستخدم إشعارًا لطلب لم يُحفظ أصلًا
 * إن تراجعت المعاملة. و`DB::afterCommit` ينفّذ فورًا خارج أي معاملة، فلا
 * يتغيّر سلوك المسارات التي لا تستخدم transactions.
 *
 * هذا مُزيّن (decorator) حول `FcmPushService` عمدًا: كل نقاط الاستدعاء
 * القائمة تحصل على الضمان الجديد دون تعديلها، ويبقى العقد `FcmSender` كما هو.
 */
class DeferredFcmSender implements FcmSender
{
    /**
     * @param  bool  $defer  يُعطَّل في الاختبارات فقط: `RefreshDatabase` يلفّ
     *                       كل اختبار في معاملة لا تُثبَّت، فـ`afterCommit` لا
     *                       ينفّذ شيئًا وتصبح كل اختبارات FCM عمياء. الاختبار
     *                       الحقيقي للتأجيل في `FcmAfterCommitTest` يبني هذا
     *                       المزيّن يدويًا بـ`defer: true` فوق معاملة حقيقية.
     */
    public function __construct(
        private readonly FcmSender $inner,
        private readonly bool $defer = true,
    ) {}

    public function enabled(): bool
    {
        return $this->inner->enabled();
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        DB::afterCommit(fn () => $this->inner->sendToUser($user, $title, $body, $data));
    }

    public function fromNotification(Notification $notification): void
    {
        if (! $this->defer) {
            $this->inner->fromNotification($notification);

            return;
        }

        // يُحمَّل النموذج داخل الـcallback: الصف قد يكون أُدرج للتوّ في نفس
        // المعاملة، وقراءته قبل الـcommit قد لا تراه.
        $id = $notification->getKey();

        DB::afterCommit(function () use ($id) {
            $fresh = Notification::find($id);

            if ($fresh !== null) {
                $this->inner->fromNotification($fresh);
            }
        });
    }
}
