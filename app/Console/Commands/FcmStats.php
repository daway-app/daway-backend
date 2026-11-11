<?php

namespace App\Console\Commands;

use App\Services\Fcm\FcmPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ملخّص تجميعي لحالات تسليم الـpush — بلا أي بيانات مستخدمين.
 *
 * الغرض: معرفة أن النظام يعمل قبل أن يشتكي مستخدم. يُطبع عدد فقط:
 * لا معرّفات، لا توكنات، لا أنواع إشعارات تفصيلية.
 *
 * مثال الإخراج:
 *   window: last 24h (since 2026-11-11 08:00)
 *   sent=120 failed=4 skipped=9 pending=0
 *   failure_codes: UNAVAILABLE=3, UNREGISTERED=1
 */
class FcmStats extends Command
{
    protected $signature = 'fcm:stats {--hours=24 : نافذة زمنية بالساعات}';

    protected $description = 'ملخّص تجميعي لحالات تسليم FCM (بلا بيانات مستخدمين)';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);

        $counts = DB::table('notifications')
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(push_status, ?) AS status, COUNT(*) AS total', ['pending'])
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) ($counts[FcmPushService::STATUS_SENT] ?? 0);
        $failed = (int) ($counts[FcmPushService::STATUS_FAILED] ?? 0);
        $skipped = (int) ($counts[FcmPushService::STATUS_SKIPPED] ?? 0);
        $pending = (int) ($counts['pending'] ?? 0);

        $this->line('window: last '.$hours.'h (since '.$since->toDateTimeString().')');
        $this->line(sprintf('sent=%d failed=%d skipped=%d pending=%d', $sent, $failed, $skipped, $pending));

        if ($failed > 0) {
            // رموز FCM المعروفة فقط؛ العمود لا يحمل نصوصًا من المزوّد.
            $codes = DB::table('notifications')
                ->where('created_at', '>=', $since)
                ->where('push_status', FcmPushService::STATUS_FAILED)
                ->whereNotNull('push_error_code')
                ->selectRaw('push_error_code, COUNT(*) AS total')
                ->groupBy('push_error_code')
                ->orderByDesc('total')
                ->limit(5)
                ->get();

            if ($codes->isNotEmpty()) {
                $this->line('failure_codes: '.$codes
                    ->map(fn ($r) => $r->push_error_code.'='.$r->total)
                    ->implode(', '));
            }
        }

        // نجاح كامل = لا فشل ولا معلّق. يُستخدم في المراقبة الآلية.
        return ($failed === 0 && $pending === 0) ? self::SUCCESS : self::FAILURE;
    }
}
