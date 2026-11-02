<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformSettlement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PAID = 'paid';
    public const CURRENCY_ILS = 'ILS';

    protected $fillable = [
        'pharmacy_id', 'pharmacy_name', 'period_start', 'period_end', 'currency',
        'orders_total', 'adjustments_total', 'net_total', 'status',
        'transfer_reference', 'paid_at', 'created_by', 'paid_by',
        'transfer_receipt_path', 'transfer_receipt_original_name', 'transfer_receipt_mime', 'transfer_receipt_size',
        'transfer_receipt_driver', 'transfer_receipt_meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'orders_total' => 'decimal:2',
            'adjustments_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'paid_at' => 'datetime',
            'transfer_receipt_size' => 'integer',
            'transfer_receipt_meta' => 'array',
        ];
    }

    public function pharmacy(): BelongsTo { return $this->belongsTo(Pharmacy::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function items(): HasMany { return $this->hasMany(PlatformSettlementItem::class, 'settlement_id'); }
    public function adjustments(): HasMany { return $this->hasMany(PlatformSettlementAdjustment::class, 'settlement_id'); }

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }
}
