<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany; // Import BelongsToMany
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Medicine extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'medicines';

    protected $fillable = [
        'trade_name',
        'trade_name_ar',
        'active_ingredient',
        'description',
        'image',
        'is_available',
        'stock',
    ];

    /**
     * علاقة: كل دواء ممكن يكون موجود بأكتر من صيدلية، عن طريق جدول pharmacy_medicines
     */
    public function pharmacyMedicines(): HasMany
    {
        return $this->hasMany(PharmacyMedicine::class);
    }

    /**
     * علاقة: الأدوية البديلة لهذا الدواء (علاقة many-to-many)
     */
    public function alternatives(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'alternative_medicine', 'medicine_id', 'alternative_id');
    }

    /**
     * علاقة: الحالات يلي هاد الدواء يكون فيها هو "البديل" لدواء تاني.
     */
    public function isAlternativeFor(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'alternative_medicine', 'alternative_id', 'medicine_id');
    }

    /**
     * علاقة: الإشعارات المرتبطة بهاد الدواء
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * علاقة Polymorphic: كل الأشخاص يلي ضافوا هاد الدواء للمفضلة تبعهم.
     */
    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favoritable');
    }

    public function availabilityNotifications(): HasMany
    {
        return $this->hasMany(AvailabilityNotification::class);
    }

    /**
     * اقتراح أدوية بديلة بناءً على المادة الفعالة.
     * يُستعمل في شاشة إضافة/تعديل دواء بالصيدلية لاقتراح بدائل لنفس المادة الفعالة.
     */
    public static function alternativesByActiveIngredient(?string $activeIngredient, ?int $excludeMedicineId = null): Collection
    {
        if (! $activeIngredient) {
            return collect();
        }

        $query = static::query()
            ->where('active_ingredient', $activeIngredient)
            ->orderBy('trade_name')
            ->limit(10);

        if ($excludeMedicineId !== null) {
            $query->where('id', '!=', $excludeMedicineId);
        }

        return $query->get();
    }
}
