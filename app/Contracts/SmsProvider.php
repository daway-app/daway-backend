<?php

namespace App\Contracts;

interface SmsProvider
{
    /**
     * هل سروت SMS مُهيأ؟ (بدون credentials → no-op)
     */
    public function enabled(): bool;

    /**
     * إرسال رسالة نصية لرقم هاتف واحد.
     *
     * @param  string  $phone  رقم الهاتف بالصيغة E.164 (مثال: +970599000001)
     * @param  string  $message  نص الرسالة
     * @return array{success: bool, message_id: ?string, error: ?string}
     *
     * لا يرمي استثناءات — الفشل يُسجَّل فقط، والعملية الأساسية لا تتوقف.
     */
    public function sendSms(string $phone, string $message): array;
}
