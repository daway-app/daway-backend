<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Throwable;

/**
 * فحص اعتماد Firebase عبر HTTP v1 **دون تسليم أي إشعار**.
 *
 * يستخدم `validateOnly` في FCM: Firebase يتحقق من الاعتماد والمشروع وبنية
 * الرسالة، ثم يتجاهل الرسالة تمامًا — لا يصل شيء إلى أي جهاز.
 *
 * الهدف: التمييز بين ثلاث حالات كان `/healthz` يدمجها في قيمة واحدة:
 *   · الاعتماد غير محمّل أصلًا        ⇒ exit 1
 *   · الاعتماد محمّل لكن مرفوض        ⇒ exit 2
 *   · الاعتماد سليم والرسالة مرفوضة   ⇒ exit 3
 *   · كل شيء سليم                     ⇒ exit 0
 *
 * لا يطبع توكنًا ولا مسار شهادة ولا أي محتوى من المزوّد — رموز فقط.
 */
class FcmProbe extends Command
{
    public const OK = 0;
    public const SDK_UNAVAILABLE = 1;
    public const AUTH_FAILED = 2;
    public const MESSAGE_REJECTED = 3;

    protected $signature = 'fcm:probe
        {--topic=daway-probe : موضوع وهمي للتحقق (لا مشتركين ⇒ لا تسليم)}
        {--token= : توكن جهاز للتحقق منه تحديدًا (لا يُطبع ولا يُسجَّل)}';

    protected $description = 'التحقق من اعتماد Firebase عبر HTTP v1 باستخدام validate_only دون إرسال أي إشعار';

    public function handle(): int
    {
        try {
            $messaging = app(Messaging::class);
        } catch (Throwable $e) {
            // لا نطبع رسالة الاستثناء: قد تحمل مسار الشهادة أو محتواها.
            $this->error('sdk_unavailable: '.$e::class);

            return self::SDK_UNAVAILABLE;
        }

        $token = $this->option('token');

        $message = CloudMessage::new()
            ->withNotification([
                'title' => 'DAWAK probe',
                'body' => 'validate-only — لن يصل هذا الإشعار إلى أي جهاز.',
            ])
            ->withData(['type' => 'probe']);

        // مع توكن: نتحقق من التوكن نفسه أيضًا. بدونه: موضوع وهمي بلا مشتركين،
        // فتنجح البنية دائمًا ولا يصل شيء.
        $message = $token
            ? $message->withToken((string) $token)
            : $message->withTopic((string) $this->option('topic'));

        try {
            $result = $messaging->send($message, validateOnly: true);

            $this->info('validated: firebase accepted the request (no message was delivered)');
            $this->line('target: '.($token ? 'token' : 'topic'));
            $this->line('message_name: '.($result['name'] ?? 'n/a'));

            return self::OK;
        } catch (MessagingException $e) {
            $status = $this->errorStatus($e);

            if ($this->isAuthError($status)) {
                $this->error('auth_failed: firebase rejected the credentials ('.$status.')');

                return self::AUTH_FAILED;
            }

            $this->error('message_rejected: firebase refused the message ('.($status ?? 'UNKNOWN').')');

            return self::MESSAGE_REJECTED;
        } catch (Throwable $e) {
            $this->error('sdk_error: '.$e::class);

            return self::SDK_UNAVAILABLE;
        }
    }

    /** حالة الخطأ من رد Firebase — رمز فقط، لا نص حر. */
    private function errorStatus(MessagingException $e): ?string
    {
        $status = $e->errors()['error']['status'] ?? null;

        return is_string($status) && preg_match('/^[A-Z_]{3,40}$/', $status) ? $status : null;
    }

    private function isAuthError(?string $status): bool
    {
        return in_array($status, ['UNAUTHENTICATED', 'PERMISSION_DENIED', 'THIRD_PARTY_AUTH_ERROR'], true);
    }
}
