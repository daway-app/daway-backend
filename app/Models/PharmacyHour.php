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
        'opening_time',    // وقت الفتح
        'closing_time',    // وقت الإغلاق
    ];

    protected function casts(): array
    {
        return [
            // نحولهم لـ datetime:H:i عشان لما نطبعهم يطلعوا بصيغة وقت واضحة (مثلاً 09:00)
            'opening_time' => 'datetime:H:i',
            'closing_time' => 'datetime:H:i',
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
