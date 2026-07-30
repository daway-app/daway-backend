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
        'user_id',              // صاحب التذكير
        'medicine_name',         // اسم الدواء (نص حر، مش مرتبط بجدول medicines مباشرة)
        'dosage',                 // الجرعة (مثلاً "500mg")
        'reminder_time',          // وقت التذكير باليوم
        'frequency',               // كل قد ايش (يومي، كل 8 ساعات...) — نص حر
        'quantity_remaining',      // الكمية المتبقية من الدواء
        'is_active',               // هل التذكير فعال أو تم إيقافه
    ];

    protected function casts(): array
    {
        return [
            'reminder_time' => 'datetime:H:i',
            'is_active' => 'boolean',
        ];
    }

    /**
     * علاقة عكسية: هاد التذكير تبع مستخدم وحيد.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
