<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Favorite extends Model
{
    protected $table = 'favorites';

    // ما فيه updated_at بجدول favorites، بس created_at
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',            // مين ضاف للمفضلة
        'favoritable_type',    // اسم الموديل (Medicine أو FirstAid مثلاً) — بفضل morphMap رح يتخزن قصير ونظيف
        'favoritable_id',      // id الصف المفضّل جوا هاد الموديل
        'created_at',
    ];

    /**
     * علاقة عكسية عادية: صاحب هاد العنصر بالمفضلة.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * هاي العلاقة الأهم بهاد الموديل: علاقة Polymorphic (متعددة الأشكال).
     *
     * morphTo() بيقول لـ Laravel: "روح شوف عمودين بنفس السطر:
     * favoritable_type (شو نوع الموديل) و favoritable_id (شو id تبعه)،
     * وبناءً عليهم رجعلي الموديل الصحيح تلقائياً".
     *
     * يعني ممكن سطر بالمفضلة يكون Medicine، وسطر تاني يكون FirstAid،
     * بنفس الجدول favorites، من دون ما نعمل جدول منفصل لكل نوع.
     *
     * الاسم 'favoritable' هون هو الجزء المشترك من اسمي العمودين
     * (favoritable_type / favoritable_id)، ولازم يطابق بالضبط
     * الاسم يلي استخدمناه بدالة morphMany() بموديلات Medicine و FirstAid.
     */
    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
