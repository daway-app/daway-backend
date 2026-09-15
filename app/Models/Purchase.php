<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فاتورة شراء من مورد.
 *
 * نفس منطق `Sale`: لا حذف، بل `is_cancelled`. لكن الفرق المحاسبي مهم —
 * الشراء **يزيد** مخزون الصيدلية ورصيد المورد، فإلغاؤه يجب أن يعكس الاثنين
 * (يتولّاه AccountingService، لا الموديل).
 */
class Purchase extends Model
{
    use HasFactory;

    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'pharmacy_id',
        'supplier_id',
        'supplier_name',
        'number',
        'subtotal',
        'discount',
        'total',
        'paid',
        'remaining',
        'payment_method',
        'status',
        'notes',
        'purchased_at',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid' => 'decimal:2',
            'remaining' => 'decimal:2',
            'purchased_at' => 'datetime',
            'is_cancelled' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
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
