<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Notification extends Model
{
    use LogsActivity;

    protected $table = 'notifications';

    // فيه بس created_at بجدول notifications
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',
        'medicine_id',
        'type',
        'message',
        'is_read',
        'created_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'medicine_id', 'type', 'is_read'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
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
