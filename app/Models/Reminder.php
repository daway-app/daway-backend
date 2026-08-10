<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use HasFactory;

    protected $table = 'reminders';

    protected $fillable = [
        'user_id',
        'medicine_name',
        'dosage',
        'reminder_date',
        'reminder_time',
        'frequency',
        'quantity_remaining',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reminder_date' => 'date:Y-m-d',
            'reminder_time' => 'datetime:H:i',
            'quantity_remaining' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
