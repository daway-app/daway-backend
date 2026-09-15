<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصروف.
 *
 * ⚠️ لا SoftDeletes: المصروف **يُلغى** (`is_cancelled = true`) ولا يُحذف.
 * الإلغاء يحرّك دفتر الصندوق، فمحتاج `cancelled_at` + `cancelled_by` للتدقيق.
 */
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'pharmacy_id',
        'expense_category_id',
        'category_key',
        'category_name',
        'amount',
        'description',
        'reference',
        'payment_method',
        'expense_date',
        'is_cancelled',
        'cancelled_at',
        'cancelled_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'is_cancelled' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    /** استثناء الملغاة — الافتراضي في كل تقرير. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_cancelled', false);
    }
}
