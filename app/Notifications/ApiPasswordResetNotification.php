<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('استعادة كلمة مرور دواك')
            ->line('طلبت استعادة كلمة المرور. إذا لم تطلب ذلك فتجاهل هذه الرسالة.')
            ->action('استعادة كلمة المرور', $this->resetUrl)
            ->line('ينتهي الرابط بعد مدة محدودة ويُستخدم مرة واحدة.');
    }
}
