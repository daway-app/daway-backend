<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * طلب إغلاق حساب (مريض أو صيدلية).
 *
 * `subject_type` = App\Models\User (مريض) أو App\Models\Pharmacy.
 * إغلاق المريض لا يحتاج مراجعة (D-3)، لكن الطلب يُسجَّل دائمًا للتدقيق.
 */
class AccountClosureRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'subject_type', 'subject_id', 'status',
        'requested_by_type', 'requested_by', 'reason', 'blockers_at_request',
        'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'blockers_at_request' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
