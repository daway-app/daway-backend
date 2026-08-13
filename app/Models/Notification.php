<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    // فيه بس created_at بجدول notifications
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',        // لمين الإشعار
        'medicine_id',     // مرتبط بأي دواء (اختياري، ممكن يكون null)
        'type',             // نوع الإشعار (مثلاً "low_stock"، "reminder"...)
        'message',           // نص الإشعار
        'is_read',           // هل المستخدم قراه أو لأ
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime', // Added this line
        ];
    }

    /**
     * علاقة عكسية: هاد الإشعار موجه لمستخدم وحيد.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * علاقة عكسية اختيارية: هاد الإشعار ممكن يكون مرتبط بدواء معين.
     * medicine_id ممكن يكون null، فـ Laravel رح يرجع null هون
     * إذا ما كان الإشعار مرتبط بدواء.
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
