<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فاتورة بيع.
 *
 * ⚠️ لا SoftDeletes هنا عمدًا: الفاتورة المحاسبية تُلغى (`status='cancelled'`)
 * ولا تُحذف. الحذف يمحو مسارًا تدقيقيًا وهو مرفوض محاسبيًا.
 *
 * `total` و `items_count` و `remaining` أعمدة مخزّنة تُحسب في الـService عند
 * الحفظ — لا تُعدَّل يدويًا من الـController.
 */
class Sale extends Model
{
    use HasFactory;

    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_CREDIT = 'credit';

    protected $fillable = [
        'pharmacy_id',
        'number',
        'customer_id',
        'customer_name',
        'subtotal',
        'discount',
        'total',
        'paid',
        'remaining',
        'refunded_amount',
        'payment_method',
        'status',
        'items_count',
        'notes',
        'created_by',
        'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid' => 'decimal:2',
            'remaining' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'items_count' => 'integer',
            'sold_at' => 'datetime',
        ];
    }

    /* ─── العلاقات ─────────────────────────────────────────────────────── */

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ─── النطاقات ─────────────────────────────────────────────────────── */

    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    /** استثناء الملغاة — يُستخدم في كل التقارير المالية افتراضيًا. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('sold_at', [$from, $to]);
    }

    /* ─── الحالة ───────────────────────────────────────────────────────── */

    /**
     * كل الحالات المسموحة — تُستخدم للتحقّق من الفلاتر الواردة من العميل.
     * وجودها هنا يمنع تكرار القائمة في كل Controller (فتنحرف إحداها).
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PAID,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_UNPAID,
            self::STATUS_REFUNDED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * طرق الدفع المسموحة. `credit` تعني آجل: لا حركة صندوق، ويزيد دين العميل.
     *
     * @return list<string>
     */
    public static function methods(): array
    {
        return [
            self::METHOD_CASH,
            self::METHOD_CARD,
            self::METHOD_BANK_TRANSFER,
            self::METHOD_CREDIT,
        ];
    }

    /**
     * مشتقّة من `paid` و `total`: الدفع الجزئي يُحسب ولا يُدخَل يدويًا.
     * إدخالها يدويًا يسمح بحالة متناقضة (paid = total و status = 'unpaid').
     */
    public static function deriveStatus(float $total, float $paid): string
    {
        if ($paid <= 0) {
            return self::STATUS_UNPAID;
        }

        if ($paid >= $total) {
            return self::STATUS_PAID;
        }

        return self::STATUS_PARTIALLY_PAID;
    }

    /**
     * صافي قيمة الفاتورة = الإجمالي − قيمة البضاعة المرتجعة.
     *
     * هو الأساس الصحيح لحالة السداد والدين: الإرجاع يُلغي بضاعة، فيجب أن
     * يُقاس «هل سُدّدت؟» على القيمة الباقية فعلاً لا على الإجمالي التاريخي.
     */
    public function netTotal(): float
    {
        return round((float) $this->total - (float) $this->refunded_amount, 2);
    }

    /** هل أُرجعت كل بضاعة الفاتورة؟ (refunded_amount ≥ total) */
    public function isFullyRefunded(): bool
    {
        return (float) $this->total > 0
            && bccomp((string) $this->refunded_amount, (string) $this->total, 2) >= 0;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * حالة السداد الواعية بالإرجاع.
     *
     * حالة واحدة (`status`) كانت تخدم مفهومين مختلفين: حالة الدفع وحالة
     * الإرجاع. هنا نفصلهما بلا عمود جديد: «مرجوعة» تُشتق من تطابق قيمة
     * الإرجاع مع الإجمالي، وبقية الحالات من صافي الفاتورة مقابل المحصَّل.
     *
     * @param float $total          الإجمالي التاريخي (قبل الإرجاع)
     * @param float $refundedAmount قيمة البضاعة المرتجعة تراكميًا
     * @param float $netTotal       total − refundedAmount
     * @param float $paid           صافي النقد المحتفظ به
     */
    public static function deriveRefundAwareStatus(float $total, float $refundedAmount, float $netTotal, float $paid): string
    {
        if ($total > 0 && bccomp((string) $refundedAmount, (string) $total, 2) >= 0) {
            return self::STATUS_REFUNDED;
        }

        return self::deriveStatus($netTotal, $paid);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
