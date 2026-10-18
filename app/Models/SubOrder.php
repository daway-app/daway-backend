<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 1 — a per-pharmacy slice of a (possibly multi-pharmacy) order.
 *
 * Each sub-order carries its own totals, payment status and lifecycle, so one
 * pharmacy can prepare/hand over/deliver without touching another pharmacy's
 * part of the same parent order. The parent order's status is *derived* from
 * its sub-orders (see Order::deriveStatusFromSubOrders()).
 */
class SubOrder extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Phase 0 — audit trail for the fields that carry money or lifecycle state.
     * `notes` is included because a pharmacy writes its cancellation/refusal
     * reason there, and that reason is exactly what an audit needs to explain
     * a stock restoration.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sub_order')
            ->logOnly([
                'status',
                'payment_status',
                'subtotal',
                'discount',
                'shipping_fee',
                'commission',
                'total',
                'pharmacy_id',
                'ready_at',
                'picked_up_at',
                'delivered_at',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'order_id',
        'pharmacy_id',
        'subtotal',
        'discount',
        'shipping_fee',
        'commission',
        'total',
        'status',
        'payment_status',
        'notes',
        'ready_at',
        'picked_up_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'commission' => 'decimal:2',
            'total' => 'decimal:2',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';

    /**
     * Progress rank used to derive the parent order status. Cancelled is
     * handled separately (it is a terminal branch, not a progress step).
     */
    public const STATUS_RANK = [
        self::STATUS_PENDING => 0,
        self::STATUS_CONFIRMED => 1,
        self::STATUS_PREPARING => 2,
        self::STATUS_READY => 3,
        self::STATUS_OUT_FOR_DELIVERY => 4,
        self::STATUS_DELIVERED => 5,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function addStatus(string $status, ?string $note = null, ?User $actor = null): void
    {
        // Rider Delivery — سجّل هوية المنفّذ (صيدلية/مندوب/أدمن) في الـtimeline.
        $actor ??= auth()->user();

        // `order_status_histories.order_id` is NOT NULL, so a sub-order history
        // row must carry its parent order id as well as its own sub_order_id.
        $this->statusHistories()->create([
            'order_id' => $this->order_id,
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
            self::STATUS_READY => [self::STATUS_OUT_FOR_DELIVERY, self::STATUS_CANCELLED],
            self::STATUS_OUT_FOR_DELIVERY => [self::STATUS_DELIVERED, self::STATUS_CANCELLED],
            self::STATUS_DELIVERED => [],
            self::STATUS_CANCELLED => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? []);
    }

    public function isExpired(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Statuses a pharmacy is allowed to push a sub-order into.
     * `pending → confirmed` is part of the checkout flow, not a pharmacy action.
     */
    public function pharmacyAllowedTargets(): array
    {
        return [
            self::STATUS_PREPARING,
            self::STATUS_READY,
            self::STATUS_OUT_FOR_DELIVERY,
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
        ];
    }
}
