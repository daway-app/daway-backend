<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تحصيل من عميل.
 *
 * يُنقص `customers.current_balance` ويزيد الصندوق — كلاهما في transaction
 * واحد عبر AccountingService. `sale_id` اختياري: قد يكون تحصيل دفعة عامة
 * على الحساب لا مرتبطة بفاتورة بعينها.
 */
class CustomerPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'pharmacy_id',
        'customer_id',
        'sale_id',
        'amount',
        'payment_method',
        'reference',
        'description',
        'paid_at',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'is_cancelled' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_cancelled', false);
    }
}
