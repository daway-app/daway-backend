<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    protected $fillable = [
        'user_id',
        'medicine_id',
        'medicine_name',
        'dosage',
        'reminder_time',
        'frequency',
        'quantity_remaining',
        'is_active'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }

    protected function casts(): array
    {
        return [
            'reminder_time' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
    }
}
