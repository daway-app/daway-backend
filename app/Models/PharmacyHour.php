<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyHour extends Model
{
    use HasFactory;

    protected $table = 'pharmacy_hours';

    protected $fillable = [
        'pharmacy_id',    // الصيدلية يلي هاد الدوام تبعها
        'day',             // اليوم: sat, sun, mon, tue, wed, thu, fri (enum بالداتابيز)
        'day_of_week',     // اسم اليوم كامل بالإنجليزي (Saturday, Sunday ...)
        'opening_time',    // وقت الفتح (عمود قديم)
        'open_time',       // وقت الفتح
        'closing_time',    // وقت الإغلاق (عمود قديم)
        'close_time',      // وقت الإغلاق
        'is_closed',       // هل الصيدلية مغلقة بهاليوم
    ];

    protected function casts(): array
    {
        return [
            // نحولهم لـ datetime:H:i عشان لما نطبعهم يطلعوا بصيغة وقت واضحة (مثلاً 09:00)
            'opening_time' => 'datetime:H:i',
            'closing_time' => 'datetime:H:i',
            'open_time' => 'datetime:H:i',
            'close_time' => 'datetime:H:i',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * علاقة عكسية: كل سطر دوام تبع صيدلية وحدة بالضبط.
     * لاحظ بالداتابيز فيه unique key على (pharmacy_id, day) يعني
     * ما ينعمل أكتر من سطر لنفس اليوم لنفس الصيدلية.
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
