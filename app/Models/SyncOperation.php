<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOperation extends Model
{
    use HasFactory;

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'pharmacy_id',
        'op_type',
        'payload',
        'client_updated_at',
        'status',
        'server_applied_at',
        'attempts',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'client_updated_at' => 'datetime',
            'server_applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
