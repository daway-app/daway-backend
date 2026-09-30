<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Cart extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'items_count',
        'subtotal',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['items_count', 'subtotal'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function calculateSubtotal(): float
    {
        return round($this->items->sum(fn ($item) => $item->price * $item->quantity), 2);
    }

    public function refreshSubtotal(): void
    {
        $this->update(['subtotal' => $this->calculateSubtotal()]);
    }

    public function itemCount(): int
    {
        return $this->items->sum(fn ($item) => $item->quantity);
    }

    public function getTotalAttribute(): float
    {
        return $this->subtotal;
    }
}