<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Pharmacy extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'pharmacies';

    /**
     * قائمة بيضاء لـ mass-assignment — C1.
     * الحقول الحساسة (is_active, avg_rating, profile_completed_at, user_id, pharmacy_custom_id)
     * غير مشمولة عمداً لمنع:
     *  - تعديل is_active عبر الـ payload (يجب أن يمر عبر admin/web flow).
     *  - تعديل avg_rating عبر الـ payload (يُحدّث عبر Observer).
     *  - تخطي إكمال البروفايل عبر ضبط profile_completed_at.
     *  - ربط الصيدلية بـ user آخر عبر payload.
     * الحقول المحذوفة تُضبط صراحة عبر methods أو في الـ seeder/admin flow.
     */
    protected $fillable = [
        'pharmacy_name',
        'address',
        'region',
        'latitude',
        'longitude',
        'phone_number',
        'logo',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',   // نفس دقة العمود بالداتابيز (10,8)
            'longitude' => 'decimal:8',  // نفس دقة العمود بالداتابيز (11,8)
            'avg_rating' => 'decimal:2', // نفس دقة العمود بالداتابيز (3,2)
            'is_active' => 'boolean',
            'profile_completed_at' => 'datetime',
        ];
    }

    /**
     * علاقة عكسية: كل صيدلية تبع مستخدم واحد بالضبط (pharmacies.user_id).
     * $pharmacy->user رح يرجعلك صاحب الصيدلية.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * علاقة: كل صيدلية عندها أكتر من سطر بجدول pharmacy_hours
     * (سطر لكل يوم بالأسبوع فيه أوقات الفتح والإغلاق).
     */
    public function hours(): HasMany
    {
        return $this->hasMany(PharmacyHour::class);
    }

    /**
     * علاقة: كل صيدلية عندها أكتر من دواء بمخزونها، عن طريق جدول pharmacy_medicines
     * (هاد الجدول pivot بس فيه أعمدة زيادة زي price و quantity، فهو نفسو صار
     * موديل مستقل PharmacyMedicine بدل ما نستخدم belongsToMany مباشرة).
     */
    public function pharmacyMedicines(): HasMany
    {
        return $this->hasMany(PharmacyMedicine::class);
    }

    /**
     * علاقة: كل صيدلية ممكن يكون عندها أكتر من تقييم من مستخدمين مختلفين.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function availabilityNotifications(): HasMany
    {
        return $this->hasMany(AvailabilityNotification::class);
    }

    public function patientInquiries(): HasMany
    {
        return $this->hasMany(PatientInquiry::class);
    }

    /**
     * هل أكمل الصيدلي تعبئة بياناته عند أول دخول؟
     */
    public function profileCompleted(): bool
    {
        return $this->profile_completed_at !== null;
    }
}

