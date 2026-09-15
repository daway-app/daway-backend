<?php

namespace App\Console\Commands;

use App\Models\MedicineEnrichmentRun;
use Illuminate\Console\Command;

/** حالات التشغيل الحالية (Resume) — دون اعتماد على CACHE. */
final class EnrichStatus extends Command
{
    protected $signature = 'medicines:enrich-status';

    protected $description = 'حالة آخر نقلات الenrichment (resume-ready) — الجداول هي مصدر الحقيقة';

    public function handle(): int
    {
        $runs = MedicineEnrichmentRun::orderByDesc('id')
            ->take(10)
            ->get();

        if ($runs->isEmpty()) {
            $this->line('لا runs بعد.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'provider', 'status', 'total', 'processed', 'matched', 'review', 'failed', 'last_offset'],
            $runs->map(fn ($r) => [$r->id, $r->provider, $r->status, number_format($r->total_records), number_format($r->processed_records), number_format($r->matched_records), number_format($r->review_records), number_format($r->failed_records), number_format($r->last_offset)])
        );

        return self::SUCCESS;
    }
}
