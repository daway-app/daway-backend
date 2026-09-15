<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * عميل الصيدلية.
 *
 * الرصيد (`current_balance`): موجب = العميل مدين لنا.
 * لا يُعدَّل مباشرة من الـController — يُحدَّث عبر AccountingLedger فقط،
 * حتى لا ينحرف عن مجموع الفواتير والدفعات.
 */
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'pharmacy_id',
        'name',
        'phone',
        'email',
        'notes',
        'credit_limit',
        'current_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** حصر السجلات على صيدلية — يُستخدم في كل استعلام لضمان العزل. */
    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    /** تجاوز حد الائتمان؟ يُستخدم لإظهار تحذير قبل البيع الآجل. */
    public function exceedsCreditLimit(float $additionalDebt = 0.0): bool
    {
        if ((float) $this->credit_limit <= 0) {
            return false; // 0 = بلا حد
        }

        return ((float) $this->current_balance + $additionalDebt) > (float) $this->credit_limit;
    }
}
