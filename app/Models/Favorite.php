<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Favorite extends Model
{
    /**
     * اسم الجدول المرتبط بالموديل.
     *
     * @var string
     */
    protected $table = 'favorites';

    /**
     * إيقاف إدارة timestamps التلقائية لأن الجدول يحتوي فقط على created_at.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * اسم عمود created_at.
     *
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * الأعمدة القابلة للتعبئة (Mass Assignment).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',            // مين ضاف للمفضلة
        'favoritable_type',   // نوع الموديل (يستخدم morphMap: 'medicine', 'first_aid')
        'favoritable_id',     // ID العنصر المفضل
        'created_at',
    ];

    /**
     * علاقة عكسية: صاحب هذا العنصر في المفضلة.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * علاقة Polymorphic (متعددة الأشكال) مع الموديلات المختلفة.
     *
     * تستخدم الـ morphMap المعرف في AppServiceProvider:
     * - 'medicine'  => \App\Models\Medicine::class
     * - 'first_aid' => \App\Models\FirstAid::class
     *
     * هذا يسمح بتخزين نوع الموديل بشكل مختصر (مثل 'medicine')
     * بدلاً من النص الكامل (مثل 'App\Models\Medicine').
     *
     * الاسم 'favoritable' هو الجزء المشترك من أسماء الأعمدة:
     * - favoritable_type
     * - favoritable_id
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
