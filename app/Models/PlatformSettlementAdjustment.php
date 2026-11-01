<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSettlementAdjustment extends Model
{
    protected $fillable = [
        'pharmacy_id', 'pharmacy_name', 'effective_period_start', 'amount', 'reason', 'created_by', 'settlement_id',
    ];

    protected function casts(): array { return ['effective_period_start' => 'date', 'amount' => 'decimal:2']; }
    public function pharmacy(): BelongsTo { return $this->belongsTo(Pharmacy::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function settlement(): BelongsTo { return $this->belongsTo(PlatformSettlement::class, 'settlement_id'); }
}
