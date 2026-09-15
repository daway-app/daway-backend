<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة صندوق — **المصدر الوحيد لرصيد النقد**.
 *
 * لا يوجد عمود "رصيد الصندوق" يمكن أن ينحرف. الرصيد يُقرأ دائمًا:
 *   SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END)
 *
 * كل حركة مالية (بيع نقدي، مصروف، دفعة، سحب) تُسجّل هنا في نفس transaction
 * مصدرها. انظر `AccountingLedger::cashBalance()`.
 */
class CashMovement extends Model
{
    use HasFactory;

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const SOURCE_SALE = 'sale';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_CUSTOMER_PAYMENT = 'customer_payment';
    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';
    public const SOURCE_WITHDRAWAL = 'withdrawal';
    public const SOURCE_DEPOSIT = 'deposit';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'pharmacy_id',
        'direction',
        'amount',
        'source_type',
        'source_id',
        'description',
        'reason',
        'created_by',
        'moved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'moved_at' => 'datetime',
        ];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    /**
     * كل أنواع مصادر الحركة — تُستخدم للتحقّق من فلاتر العميل.
     * وجودها هنا يمنع تكرار القائمة في الـControllers.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        return [
            self::SOURCE_SALE,
            self::SOURCE_EXPENSE,
            self::SOURCE_PURCHASE,
            self::SOURCE_CUSTOMER_PAYMENT,
            self::SOURCE_SUPPLIER_PAYMENT,
            self::SOURCE_WITHDRAWAL,
            self::SOURCE_DEPOSIT,
            self::SOURCE_ADJUSTMENT,
        ];
    }

    /**
     * المصادر اليدوية فقط (بقية المصادر تُنشأ تلقائيًا من عمليات أخرى).
     *
     * @return list<string>
     */
    public static function manualSources(): array
    {
        return [
            self::SOURCE_WITHDRAWAL,
            self::SOURCE_DEPOSIT,
            self::SOURCE_ADJUSTMENT,
        ];
    }

    /**
     * المبلغ بالإشارة — `in` موجب و`out` سالب. يُستخدم في تقارير الدفتر
     * وفي حساب الرصيد، ويوحّد المنطق في مكان واحد.
     */
    public function signedAmount(): float
    {
        return $this->direction === self::DIRECTION_IN
            ? (float) $this->amount
            : -1 * (float) $this->amount;
    }
}
