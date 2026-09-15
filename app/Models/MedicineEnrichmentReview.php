<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف مراجعة يدوية للاقتراحات المنخفضة الثقة — لا تدخل الكتالوج قبل approve.
 */
class MedicineEnrichmentReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** default لا يضّعه migration — ناجر الدالة وصفاحة (attribute casting) */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'moh_medicine_id',
        'provider',
        'provider_payload',
        'reason',
        'source_reference',
        'confidence',
        'status',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_payload' => 'array',
            'confidence' => 'decimal:3',
            'reviewed_at' => 'datetime',
        ];
    }

    public function mohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class);
    }
}
