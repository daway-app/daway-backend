<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Admin-managed destination bank for manual bank transfers.
 *
 * The account number and IBAN are deliberately excluded from the activity log:
 * the log is read by every admin and must not become a second unguarded copy
 * of full banking details. The submission snapshots keep the historical values.
 */
class PaymentBank extends Model
{
    use HasFactory, LogsActivity;

    public const MAX_ACTIVE = 3;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_bank')
            ->logOnly([
                'bank_name',
                'account_holder',
                'branch',
                'is_active',
                'sort_order',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'bank_name',
        'account_holder',
        'account_number',
        'iban',
        'branch',
        'instructions',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(OrderPaymentSubmission::class, 'bank_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
