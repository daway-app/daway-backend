<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSettlementItem extends Model
{
    protected $fillable = ['settlement_id', 'sub_order_id', 'sub_order_id_snapshot', 'order_id_snapshot', 'amount'];
    protected function casts(): array { return ['amount' => 'decimal:2']; }
    public function settlement(): BelongsTo { return $this->belongsTo(PlatformSettlement::class, 'settlement_id'); }
    public function subOrder(): BelongsTo { return $this->belongsTo(SubOrder::class, 'sub_order_id'); }
}
