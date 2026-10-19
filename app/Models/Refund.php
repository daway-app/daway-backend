<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Refund extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Phase 0 — a refund moves money out and puts stock back, so every field
     * that describes it is audit-worthy. `created_by` is included so the log
     * itself names the actor even when no authenticated causer is present
     * (e.g. a console/backfill run).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('refund')
            ->logOnly([
                'status',
                'amount',
                'cash_refunded',
                'debt_cancelled',
                'reason',
                'sale_id',
                'pharmacy_id',
                'created_by',
                'refunded_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'pharmacy_id',
        'sale_id',
        'created_by',
        'refunded_at',
        'amount',
        'cash_refunded',
        'debt_cancelled',
        'reason',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cash_refunded' => 'decimal:2',
        'debt_cancelled' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * اسم بديل لنفس العلاقة أعلاه.
     *
     * ⚠️ الـviews (`pharmacy.accounting.refunds.{index,show}`) تقرأ
     * `$refund->createdBy` بينما الـAPI يقرأ `$refund->creator`. بدل تعديل
     * أيٍّ منهما (وكلاهما يعمل)، نُعرّف الاسمين على نفس المفتاح `created_by`
     * — فتظلّ العلاقة واحدة، وتعمل كل الجهات المستهلكة بلا تغيير.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
