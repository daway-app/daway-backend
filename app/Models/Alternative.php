<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alternative extends Model
{
    use HasFactory;

    protected $table = 'alternatives';

    /**
     * ملاحظة مهمة: جدول alternatives ما فيه عمود updated_at، فيه بس created_at.
     * يعني لازم نعطل نظام الـ timestamps الافتراضي تبع Laravel (يلي بيتوقع
     * العمودين مع بعض)، ونحدد بس اسم عمود الإنشاء يدوياً تحت.
     */
    public $timestamps = false; // نوقف الإدارة التلقائية لـ created_at/updated_at
    const CREATED_AT = 'created_at'; // بس منسجل وقت الإنشاء يدوياً لما نحتاج

    protected $fillable = [
        'medicine_id',              // الدواء الأصلي
        'alternative_medicine_id',  // الدواء البديل المقترح
        'notes',                     // ملاحظات عن سبب أو طريقة الاستبدال
        'created_at',
    ];

    /**
     * علاقة: الدواء الأصلي (المصدر) لهاد السطر.
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'medicine_id');
    }

    /**
     * علاقة: الدواء البديل المقترح.
     * لاحظ إنه belongsTo التانية بترجع لنفس موديل Medicine
     * بس عن طريق عمود مختلف (alternative_medicine_id).
     */
    public function alternativeMedicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'alternative_medicine_id');
    }
}
