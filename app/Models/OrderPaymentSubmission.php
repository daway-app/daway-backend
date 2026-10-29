<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One declared bank transfer for a parent order.
 *
 * Receipt bytes are never logged; only the review state and the declared
 * amount are audit-worthy. Bank details are snapshotted at submission time so
 * editing a bank never rewrites history.
 */
class OrderPaymentSubmission extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const AMOUNT_MATCHES = 'matches';
    public const AMOUNT_UNDER = 'under';
    public const AMOUNT_OVER = 'over';

    public const AMOUNT_STATUSES = [
        self::AMOUNT_MATCHES,
        self::AMOUNT_UNDER,
        self::AMOUNT_OVER,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('order_payment_submission')
            ->logOnly([
                'status',
                'amount',
                'bank_id',
                'review_note',
                'rejection_reason',
                'reviewed_by',
                'reviewed_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'order_id',
        'bank_id',
        'bank_name',
        'account_holder',
        'account_number',
        'iban',
         'amount',
        'order_grand_total',
        'amount_status',
        'overage_amount',
        'sender_name',
        'transfer_reference',
        'transferred_at',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime',
        'receipt_size',
        'status',
        'review_note',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'order_grand_total' => 'decimal:2',
            'overage_amount' => 'decimal:2',
            'transferred_at' => 'date',
            'receipt_size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(PaymentBank::class, 'bank_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeUnderReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNDER_REVIEW);
    }
}
