<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class OrderItem extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Phase 0 — an order line is the price snapshot a settlement is computed
     * from, so quantity / unit_price / total_price are logged. `notes`-like
     * free text does not exist here, and there is nothing personal to strip.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('order_item')
            ->logOnly([
                'order_id',
                'sub_order_id',
                'pharmacy_id',
                'pharmacy_medicine_id',
                'quantity',
                'unit_price',
                'total_price',
                'availability_status',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'order_id',
        'sub_order_id',
        'pharmacy_id',
        'pharmacy_medicine_id',
        'moh_medicine_id',
        'medicine_trade_name',
        'medicine_description',
        'quantity',
        'unit_price',
        'total_price',
        'availability_status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Phase 1 — the pharmacy sub-order this line belongs to.
     * NULL for legacy (pre-Phase-1) items.
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function pharmacyMedicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class);
    }

    public function mohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class, 'moh_medicine_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id');
    }

    protected static function booted(): void
    {
        static::creating(function ($item) {
            $item->total_price = round($item->unit_price * $item->quantity, 2);
        });
    }
}