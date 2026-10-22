<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Phase 0 — audit trail for financially/state-sensitive fields only.
     *
     * `snapshot` and `address_snapshot` are deliberately EXCLUDED: they carry
     * patient delivery data and the full cart payload, and the audit log is
     * read by any admin — an audit trail must not become a second, unguarded
     * copy of personal data.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('order')
            ->logOnly([
                'status',
                'payment_status',
                'payment_method',
                'payment_reviewed_by',
                'payment_reviewed_at',
                'payment_rejection_reason',
                'payment_problem_reported_at',
                'subtotal',
                'discount',
                'total',
                'delivery_fee',
                'coupon_code',
                'pharmacy_id',
                'address_id',
                'ready_at',
                'delivered_at',
                'rider_id',
                'assigned_by',
                'assigned_at',
                'picked_up_at',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'user_id',
        'address_id',
        'pharmacy_id',
        'external_order_id',
        'subtotal',
        'discount',
        'total',
        'delivery_fee',
        'coupon_code',
        'notes',
        'status',
        'ready_at',
        'delivered_at',
        'rider_id',
        'assigned_by',
        'assigned_at',
        'picked_up_at',
        'payment_status',
        'payment_method',
        'payment_reviewed_by',
        'payment_reviewed_at',
        'payment_rejection_reason',
        'payment_problem_reported_at',
        'snapshot',
        'address_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'payment_reviewed_at' => 'datetime',
            'payment_problem_reported_at' => 'datetime',
            'snapshot' => 'array',
            'address_snapshot' => 'array',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';

    /**
     * Rider Delivery — حالة مستقلة عن `out_for_delivery` (قرار معتمد).
     * تُعادلها: «استلم المندوب كل الطلبات الفرعية المطلوبة، ولم ينطلق بعد».
     */
    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_UNDER_REVIEW = 'under_review';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_REJECTED = 'rejected';
    public const PAYMENT_PROBLEM_REPORTED = 'problem_reported';

    /**
     * Payment phase — states `orders.payment_status` may hold.
     * The parent order is the single source of truth; sub-orders only mirror
     * this value for display.
     */
    public const PAYMENT_STATUSES = [
        self::PAYMENT_PENDING,
        self::PAYMENT_UNDER_REVIEW,
        self::PAYMENT_PAID,
        self::PAYMENT_REJECTED,
        self::PAYMENT_PROBLEM_REPORTED,
    ];

    public const PAYMENT_METHOD_BANK_TRANSFER = 'bank_transfer';

    /**
     * Rider Delivery — مراحل التوصيل التي يملكها المندوب على الطلب الأب.
     * بعد دخول أي منها، يُجمَّد الاشتقاق من الطلبات الفرعية (الصيدلية لم تعد
     * مصدر حقيقة حالة الأب) إلا أن الحالتين النهائيتين تُعالجان كما هما.
     */
    public const DELIVERY_PHASE = [
        self::STATUS_PICKED_UP,
        self::STATUS_OUT_FOR_DELIVERY,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rider Delivery — المندوب المُسند حالياً (NULL = غير مُسند). */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /** Rider Delivery — الأدمن الذي أسند الطلب (تدقيق). */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Phase 1 — per-pharmacy slices of this order.
     * Legacy (pre-Phase-1) orders have zero sub-orders; their items live
     * directly on the order with `sub_order_id = NULL`.
     */
    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class);
    }

    /**
     * Payment phase — declared bank transfers, newest first via the latest
     * convenience relationship below.
     */
    public function paymentSubmissions(): HasMany
    {
        return $this->hasMany(OrderPaymentSubmission::class);
    }

    public function latestPaymentSubmission(): HasOne
    {
        return $this->hasOne(OrderPaymentSubmission::class)->latestOfMany();
    }

    public function paymentProblems(): HasMany
    {
        return $this->hasMany(PaymentProblem::class);
    }

    public function openPaymentProblem(): HasOne
    {
        return $this->hasOne(PaymentProblem::class)
            ->where('status', PaymentProblem::STATUS_OPEN)
            ->latestOfMany();
    }

    public function paymentReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_reviewed_by');
    }

    public function statusHistories(): HasMany
    {
        // Phase 1: sub-order history rows also carry the parent `order_id`
        // (that column is NOT NULL), so the PARENT timeline must explicitly
        // exclude them via `sub_order_id IS NULL`.
        return $this->hasMany(OrderStatusHistory::class)
            ->whereNull('sub_order_id')
            ->latest();
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function addStatus(string $status, ?string $note = null, ?User $actor = null): void
    {
        // Rider Delivery — سجّل هوية المنفّذ إن توفّرت (مندوب/أدمن/صيدلية/مريض).
        $actor ??= auth()->user();

        $this->statusHistories()->create([
            'status' => $status,
            'note' => $note,
            'status_changed_at' => now(),
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->role,
        ]);

        $this->update(['status' => $status]);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
            self::STATUS_CONFIRMED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
            self::STATUS_PREPARING => [self::STATUS_READY, self::STATUS_CANCELLED],
            // `ready → out_for_delivery` يبقى متاحاً لمسار الصيدلية التاريخي؛
            // `ready → picked_up` هو مدخل مرحلة المندوب.
            self::STATUS_READY => [
                self::STATUS_PICKED_UP,
                self::STATUS_OUT_FOR_DELIVERY,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_PICKED_UP => [
                self::STATUS_OUT_FOR_DELIVERY,
                self::STATUS_DELIVERED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_OUT_FOR_DELIVERY => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
            self::STATUS_DELIVERED => [],
            self::STATUS_CANCELLED => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? []);
    }

    /**
     * Rider Delivery — حالات الأب التي يُسمح للمندوب بضبطها.
     * `pending..ready` تبقى من اختصاص الصيدلية؛ المندوب يبدأ من `picked_up`.
     */
    public function riderAllowedTargets(): array
    {
        return [
            self::STATUS_PICKED_UP,
            self::STATUS_OUT_FOR_DELIVERY,
            self::STATUS_DELIVERED,
        ];
    }

    /** Rider Delivery — true إذا دخل الأب مرحلة التسليم التي يملكها المندوب. */
    public function hasStartedPickup(): bool
    {
        return in_array($this->status, self::DELIVERY_PHASE, true);
    }

    public function hasRider(): bool
    {
        return $this->rider_id !== null;
    }

    public function isExpired(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Phase 1 — true when the checkout spanned more than one pharmacy.
     * Legacy orders and single-pharmacy orders keep `pharmacy_id` set and
     * have exactly one sub-order.
     */
    public function isMultiPharmacy(): bool
    {
        $count = $this->relationLoaded('subOrders')
            ? $this->subOrders->count()
            : $this->subOrders()->count();

        return $count > 1;
    }

    /**
     * Phase 1 — derive the parent status from its sub-orders.
     *
     * Rules:
     *  - no sub-orders (legacy order) → keep the order's own status;
     *  - every sub-order cancelled   → cancelled;
     *  - every active sub-order delivered → delivered;
     *  - otherwise → the least-advanced ACTIVE sub-order (the bottleneck),
     *    so the parent only advances once *all* pharmacies have advanced.
     */
    public function deriveStatusFromSubOrders(): string
    {
        // Rider Delivery — بعد بدء الاستلام يصبح المندوب مصدر حقيقة حالة الأب،
        // فلا يجوز أن يعيد اشتقاقٌ من الطلبات الفرعية (صيدلية تؤكد/تلغي)
        // حالةَ التوصيل إلى الوراء.
        if ($this->hasRider() && $this->hasStartedPickup()) {
            return $this->status;
        }

        $subOrders = $this->relationLoaded('subOrders')
            ? $this->subOrders
            : $this->subOrders()->get();

        if ($subOrders->isEmpty()) {
            return $this->status;
        }

        $active = $subOrders->reject(
            fn (SubOrder $s) => $s->status === SubOrder::STATUS_CANCELLED
        );

        if ($active->isEmpty()) {
            return self::STATUS_CANCELLED;
        }

        if ($active->every(fn (SubOrder $s) => $s->status === SubOrder::STATUS_DELIVERED)) {
            return self::STATUS_DELIVERED;
        }

        $minRank = $active->min(
            fn (SubOrder $s) => SubOrder::STATUS_RANK[$s->status] ?? 0
        );

        foreach (SubOrder::STATUS_RANK as $status => $rank) {
            if ($rank === $minRank) {
                return $status;
            }
        }

        return self::STATUS_PENDING;
    }

    /**
     * Phase 1 — recompute and persist the parent status. Records the derived
     * transition in the parent timeline only when it actually changed.
     */
    public function syncStatusFromSubOrders(): void
    {
        $derived = $this->deriveStatusFromSubOrders();

        if ($derived !== $this->status) {
            $this->addStatus($derived, 'تحديث تلقائي حسب حالة طلبات الصيدليات الفرعية');
        }
    }
}