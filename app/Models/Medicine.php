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

    /**
     * خريطة trade_name → id للكتالوج المحلي، لربط صفوف كتالوج وزارة الصحة
     * بالدواء المحلي المطابق.
     *
     * الجسر الوحيد المتاح حالياً بين الكتالوجين هو مطابقة trade_name — نفس
     * القاعدة المستخدمة في MedicineCatalogService::findOrCreateFromMoh، لذا
     * النتيجة هنا تطابق ما يعيده ذلك الـ service.
     *
     * @param  array<int, string|null>  $tradeNames
     * @return array<string, int>
     */
    public static function idsByTradeName(array $tradeNames): array
    {
        $names = array_values(array_unique(array_filter($tradeNames, fn ($name): bool => $name !== null && $name !== '')));

        if ($names === []) {
            return [];
        }

        $map = [];

        // ترتيب تصاعدي بالـ id: أصغر id يفوز عند تكرار الاسم — نفس دلالة first()
        static::query()
            ->whereIn('trade_name', $names)
            ->orderBy('id')
            ->get(['id', 'trade_name'])
            ->each(function (self $medicine) use (&$map): void {
                $map[$medicine->trade_name] ??= $medicine->id;
            });

        return $map;
    }
}
