<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FirstAid extends Model
{
    protected $table = 'first_aid';

    /**
     * جدول first_aid فيه بس updated_at (ما فيه created_at أصلاً حسب السكيما).
     * فمنوقف الإدارة التلقائية الافتراضية (يلي بتتوقع العمودين مع بعض)
     * ومنحدد يدوياً بس عمود التحديث.
     */
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'title',                // عنوان حالة الإسعاف الأولي (مثلاً "حروق")
        'category',              // التصنيف (مثلاً "جروح"، "حروق"، "اختناق")
        'instructions_steps',     // خطوات الإسعاف (نص طويل، ممكن JSON أو نص مرقم)
        'image_icon',              // أيقونة/صورة توضيحية
        'updated_at',
    ];

    /**
     * علاقة Polymorphic: كل الأشخاص يلي ضافوا هاي الحالة الإسعافية للمفضلة.
     * نفس فكرة favoritedBy() بموديل Medicine بالضبط.
     */
    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }
}
