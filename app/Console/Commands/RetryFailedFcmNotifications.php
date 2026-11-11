<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * إعادة إرسال الإشعارات التي فشل push‑ها بالكامل.
 *
 * لماذا أمر وليس Job: `render.yaml` يشغّل خدمة `web` فقط، و`QUEUE_CONNECTION
 * =database` بلا عامل. أي طابور لن يُستهلك، فالإشعارات ستختفي بصمت. الأمر
 * يعمل يدويًا أو عبر cron بلا بنية إضافية.
 */
class RetryFailedFcmNotifications extends Command
{
    protected $signature = 'fcm:retry-failed {--limit=100 : أقصى عدد إشعارات في الدفعة} {--max=3 : أقصى عدد محاولات لكل إشعار}';

    protected $description = 'إعادة محاولة إرسال إشعارات FCM التي فشلت بالكامل';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $max = max(1, (int) $this->option('max'));

        $result = $dispatcher->retryFailed($limit, $max);

        $this->info(sprintf(
            'retried=%d sent=%d failed=%d',
            $result['retried'],
            $result['sent'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
