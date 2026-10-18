<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 0 — durable idempotency record for the patient checkout flow.
 *
 * A row per (user_id, key_hash). Storing it in the DB (not cache) makes the
 * guarantee concurrent-safe and survives process restarts.
 */
class CheckoutIdempotency extends Model
{
    protected $fillable = [
        'user_id',
        'key_hash',
        'order_id',
    ];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
