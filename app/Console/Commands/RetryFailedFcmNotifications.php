<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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

        // استخدم مخزن قاعدة البيانات للقفل فقط: Cache الافتراضي في Render هو file
        // وغير مشترك بين حاوية الويب وخدمة Cron المستقلة. كلاهما يتصل بنفس
        // قاعدة Aiven، فيمنع هذا القفل تكرار محاولة إرسال الدفعة بالتزامن.
        // يظل Cache الافتراضي للتطبيق كما هو، والقفل يُحرر في finally.
        $lock = Cache::store('database')->lock('fcm:retry-failed', 300);

        if (! $lock->get()) {
            $this->warn('retry already running — skipped');

            return self::SUCCESS;
        }

        try {
            $result = $dispatcher->retryFailed($limit, $max);
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'retried=%d sent=%d failed=%d',
            $result['retried'],
            $result['sent'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
