<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'minimum_order_amount',
        'maximum_discount',
        'starts_at',
        'expires_at',
        'usage_limit',
        'used_count',
        'per_user_limit',
        'users_used_count',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_discount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->usage_limit > 0 && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $orderTotal): float
    {
        if ($orderTotal < $this->minimum_order_amount) {
            return 0;
        }

        if ($this->type === 'percentage') {
            $discount = $orderTotal * ($this->value / 100);
        } else {
            $discount = (float) $this->value;
        }

        if ($this->maximum_discount !== null) {
            $discount = min($discount, (float) $this->maximum_discount);
        }

        return round($discount, 2);
    }

    public function canApplyToOrder(float $orderTotal): bool
    {
        if ($orderTotal < $this->minimum_order_amount) {
            return false;
        }

        if (! $this->isValid()) {
            return false;
        }

        return true;
    }
}