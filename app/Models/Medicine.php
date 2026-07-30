<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Medicine extends Model
{
    use HasFactory;

    protected $table = 'medicines';

    protected $fillable = [
        'trade_name',          // الاسم التجاري للدواء (زي Panadol)
        'active_ingredient',   // المادة الفعالة (زي Paracetamol)
        'description',          // وصف الدواء
        'image',                // مسار صورة الدواء
    ];

    /**
     * علاقة: كل دواء ممكن يكون موجود بأكتر من صيدلية، عن طريق جدول pharmacy_medicines
     * (نفس فكرة العلاقة يلي شرحناها بموديل Pharmacy بس من الجهة التانية).
     */
    public function pharmacyMedicines(): HasMany
    {
        return $this->hasMany(PharmacyMedicine::class);
    }

    /**
     * علاقة: الأدوية البديلة لهاد الدواء (جدول alternatives).
     * هون الدواء الحالي هو "medicine_id" وبنجيب كل الأدوية البديلة إلو
     * (alternative_medicine_id) عن طريق موديل Alternative الوسيط.
     */
    public function alternatives(): HasMany
    {
        return $this->hasMany(Alternative::class, 'medicine_id');
    }

    /**
     * علاقة: الحالات يلي هاد الدواء يكون فيها هو "البديل" لدواء تاني.
     * يعني منقلب الاتجاه: بنجيب كل سطر بجدول alternatives يلي
     * alternative_medicine_id فيه بيساوي id تبع هاد الدواء.
     */
    public function isAlternativeFor(): HasMany
    {
        return $this->hasMany(Alternative::class, 'alternative_medicine_id');
    }

    /**
     * علاقة: الإشعارات المرتبطة بهاد الدواء (مثلاً تنبيه "خلص من المخزون"
     * أو تنبيه انتهاء صلاحية). medicine_id بجدول notifications ممكن يكون null.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * علاقة Polymorphic: كل الأشخاص يلي ضافوا هاد الدواء للمفضلة تبعهم.
     * هون بنستخدم MorphMany بدل HasMany لأنه جدول favorites بيقدر
     * يخزن أي نوع Model (دواء، أو first_aid، إلخ) مش بس Medicine.
     *
     * 'favoritable' هون لازم يطابق بالضبط الاسم يلي حطيناه بدالة favoritable()
     * جوا موديل Favorite (morphTo('favoritable')).
     */
    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }
}
