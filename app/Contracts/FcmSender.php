<?php

namespace App\Contracts;

use App\Models\Notification;
use App\Models\User;

interface FcmSender
{
    /**
     * هل Firebase مُهيأ؟ (بدون credentials → no-op)
     */
    public function enabled(): bool;

    /**
     * إرسال push لكل توكنات المستخدم المسجلة.
     * لا يرمي استثناءات — الفشل يُسجَّل فقط، والتوكنات الميتة تُحذف.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void;

    /**
     * إرسال push لسجل Notification جديد — يُستدعى فقط بعد نجاح إنشاء السجل.
     */
    public function fromNotification(Notification $notification): void;
}
