<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Rating extends Model
{
    use LogsActivity;

    protected $table = 'ratings';

    /**
     * جدول ratings ما فيه عمود updated_at (بس created_at)، فمنوقف
     * الإدارة التلقائية متل ما سوينا بالضبط بموديل Alternative.
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',        // مين قيّم
        'pharmacy_id',     // أي صيدلية انقيمت
        'stars_rating',     // عدد النجوم (رقم صحيح موجب)
        'comment',           // تعليق نصي اختياري
        'created_at',
    ];

    /**
     * علاقة عكسية: هاد التقييم كتبه مستخدم وحيد.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * علاقة عكسية: هاد التقييم لصيدلية وحيدة.
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
