<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncState extends Model
{
    use HasFactory;

    protected $table = 'sync_state';

    protected $fillable = [
        'user_id',
        'entity',
        'last_pulled_at',
    ];

    protected function casts(): array
    {
        return [
            'last_pulled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
