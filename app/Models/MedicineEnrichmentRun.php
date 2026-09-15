<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * مصدر الحقيقة لحالة التشغيل (Resume) — الـCACHE مجرد تحسين وليس حالة.
 */
class MedicineEnrichmentRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'provider',
        'status',
        'batch_size',
        'total_records',
        'processed_records',
        'matched_records',
        'barcode_matches',
        'image_matches',
        'review_records',
        'failed_records',
        'last_offset',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
