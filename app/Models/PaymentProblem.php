<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A patient-reported problem with a bank transfer.
 *
 * The free-text message is not copied into the activity log (it may carry
 * personal details); only the lifecycle fields are audited.
 */
class PaymentProblem extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const CATEGORY_BANK_DETAILS = 'bank_details';

    public const CATEGORY_AMOUNT = 'amount';

    public const CATEGORY_RECEIPT = 'receipt';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_BANK_DETAILS,
        self::CATEGORY_AMOUNT,
        self::CATEGORY_RECEIPT,
        self::CATEGORY_OTHER,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_problem')
            ->logOnly([
                'status',
                'category',
                'submission_id',
                'admin_note',
                'resolved_by',
                'resolved_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'order_id',
        'submission_id',
        'category',
        'message',
        'status',
        'admin_note',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(OrderPaymentSubmission::class, 'submission_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
