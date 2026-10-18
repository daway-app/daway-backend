<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * شريحة رسوم توصيل واحدة (Phase 1).
 *
 * الشريحة تغطّي المدى [min_distance_meters, max_distance_meters]:
 *   · max = NULL ⇒ مفتوحة إلى ما لا نهاية (الشريحة الأخيرة عادةً).
 *
 * قواعد التحقق من مجموعة الشرائح الفعّالة (بلا تداخل/فجوات) في
 * `App\Services\DeliveryPricingService::validateActiveSet` — لا هنا، حتى يبقى
 * النموذج تصريحًا للبيانات فقط.
 */
class DeliveryFeeTier extends Model
{
    protected $fillable = [
        'min_distance_meters',
        'max_distance_meters',
        'fee',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_distance_meters' => 'integer',
            'max_distance_meters' => 'integer',
            'fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** الشرائح الفعّالة فقط، مرتّبة بالمسافة. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** هل تغطّي هذه الشريحة مسافةً معيّنة (بالمتر)؟ */
    public function coversMeters(int $meters): bool
    {
        if ($meters < $this->min_distance_meters) {
            return false;
        }

        return $this->max_distance_meters === null
            || $meters <= $this->max_distance_meters;
    }
}
