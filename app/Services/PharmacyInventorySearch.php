<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Support\Haversine;
use App\Support\PharmacyAvailability;
use App\Support\StockStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * البحث في مخزون الصيدليات الحقيقي عن دواء معيّن، مع المسافة والترتيب.
 *
 * المصدر الوحيد للحقيقة هنا هو `pharmacy_medicines` + `pharmacies`:
 * السعر من `pharmacy_medicines.price` (وليس `moh_medicines.official_price`)،
 * والتوفر من الكمية/الفلاغ/حالة الصيدلية.
 *
 * كل الحسابات حتمية (deterministic) وتتمّ في الـ Backend — لا يشارك أي نموذج
 * لغوي في حساب مسافة أو ترتيب أو سعر.
 *
 * الأداء: عند توفّر إحداثيات نُطبّق Bounding Box في SQL أولاً (يستخدم فهرس
 * الإحداثيات) ثم نحسب Haversine في PHP على مجموعة صغيرة، بدل تحميل كل الصيدليات.
 */
final class PharmacyInventorySearch
{
    public const SORT_NEAREST = 'nearest';

    public const SORT_CHEAPEST = 'cheapest';

    public const SORT_BEST = 'best';

    public const SORTS = [self::SORT_NEAREST, self::SORT_CHEAPEST, self::SORT_BEST];

    /** سقف أمان لعدد الصفوف المسحوبة قبل الترتيب النهائي. */
    private const MAX_CANDIDATE_ROWS = 500;

    /** متوسط طول الدرجة الواحدة بالكيلومترات (تقريب كافٍ لصندوق الإحاطة). */
    private const KM_PER_DEGREE = 111.045;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forMedicine(
        int $medicineId,
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusKm = 10,
        string $sort = self::SORT_NEAREST,
        int $limit = 20,
    ): array {
        $hasGeo = $latitude !== null && $longitude !== null;
        $sort = in_array($sort, self::SORTS, true) ? $sort : self::SORT_NEAREST;

        $query = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->where('pm.medicine_id', $medicineId)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true);

        if ($hasGeo) {
            [$minLat, $maxLat, $minLng, $maxLng] = $this->boundingBox($latitude, $longitude, $radiusKm);

            // صندوق الإحاطة يُطبَّق على الصيدليات ذات الإحداثيات فقط، بينما
            // الصيدليات بلا إحداثيات تبقى ضمن النتائج بمسافة null.
            // السببان: (1) لا نخترع مسافة، (2) لا نخفي توفّراً حقيقياً،
            // (3) نفس سلوك GET /api/patient/medicines/{id}/availability الحالي
            //     الذي يُبقيها ويضع distance_km = null بدل استبعادها.
            $query->where(function ($geo) use ($minLat, $maxLat, $minLng, $maxLng) {
                $geo->where(function ($located) use ($minLat, $maxLat, $minLng, $maxLng) {
                    $located->whereNotNull('p.latitude')
                        ->whereNotNull('p.longitude')
                        ->whereBetween('p.latitude', [$minLat, $maxLat])
                        ->whereBetween('p.longitude', [$minLng, $maxLng]);
                })
                    ->orWhereNull('p.latitude')
                    ->orWhereNull('p.longitude');
            });
        }

        $rows = $query
            ->select(
                'pm.price',
                'pm.quantity',
                'pm.is_available as pm_is_available',
                'p.id as pharmacy_id',
                'p.pharmacy_name',
                'p.address',
                'p.region',
                'p.phone_number',
                'p.latitude',
                'p.longitude',
                'p.avg_rating',
                'p.is_active as p_is_active',
            )
            ->orderBy('pm.id')
            ->limit(self::MAX_CANDIDATE_ROWS)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $hoursByPharmacy = $this->hoursFor($rows->pluck('pharmacy_id')->all());
        $now = Carbon::now();

        $results = [];

        foreach ($rows as $row) {
            $pharmacyLat = $row->latitude !== null ? (float) $row->latitude : null;
            $pharmacyLng = $row->longitude !== null ? (float) $row->longitude : null;

            $distance = null;

            if ($hasGeo && $pharmacyLat !== null && $pharmacyLng !== null) {
                $distance = Haversine::kmBetween($latitude, $longitude, $pharmacyLat, $pharmacyLng);

                // فلترة نصف القطر الدقيقة بعد صندوق الإحاطة الخشن.
                // الصيدليات بلا إحداثيات تتخطّى هذا الفحص (مسافتها غير معروفة)
                // وتبقى في النتائج بـ distance_km = null — كما في الـAPI الحالي.
                if ($distance > $radiusKm) {
                    continue;
                }
            }

            $results[] = [
                'pharmacy_id' => (int) $row->pharmacy_id,
                'name' => $row->pharmacy_name,
                'price' => (float) $row->price,
                'quantity' => (int) $row->quantity,
                // القيم تُقرأ من الصف نفسه (لا ثوابت) — فلو تغيّرت فلاتر الاستعلام
                // لاحقاً تبقى الحالة صادقة بدل أن تكذب بصمت.
                'availability' => StockStatus::of(
                    (bool) $row->pm_is_available,
                    (int) $row->quantity,
                    (bool) $row->p_is_active,
                ),
                'distance_km' => $distance !== null ? round($distance, 2) : null,
                'phone' => $row->phone_number,
                'latitude' => $pharmacyLat,
                'longitude' => $pharmacyLng,
                'is_open_now' => $this->isOpenNow($hoursByPharmacy[$row->pharmacy_id] ?? collect(), $now),
                'rating' => $row->avg_rating !== null ? round((float) $row->avg_rating, 1) : null,
                'address' => $row->address,
                'region' => $row->region,
            ];
        }

        $this->sort($results, $sort);

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * صندوق إحاطة خشن حول نقطة المستخدم — يقلّص الصفوف في SQL قبل Haversine.
     *
     * @return array{0:float,1:float,2:float,3:float} [minLat, maxLat, minLng, maxLng]
     */
    private function boundingBox(float $latitude, float $longitude, int $radiusKm): array
    {
        $deltaLat = $radiusKm / self::KM_PER_DEGREE;

        // عند خطوط العرض القطبية يقترب cos من الصفر → نمنع قسمة على صفر
        // ونسمح بمدى كامل لخط الطول بدل قيمة لا نهائية.
        $cosLat = cos(deg2rad($latitude));
        $deltaLng = abs($cosLat) < 0.000001
            ? 180.0
            : min(180.0, $radiusKm / (self::KM_PER_DEGREE * abs($cosLat)));

        return [
            max(-90.0, $latitude - $deltaLat),
            min(90.0, $latitude + $deltaLat),
            max(-180.0, $longitude - $deltaLng),
            min(180.0, $longitude + $deltaLng),
        ];
    }

    /**
     * ساعات عمل الصيدليات المعنية في استعلام واحد (بدل استعلام لكل صيدلية).
     *
     * @param  array<int, int>  $pharmacyIds
     * @return Collection<int, Collection<int, PharmacyHour>>
     */
    private function hoursFor(array $pharmacyIds): Collection
    {
        if ($pharmacyIds === []) {
            return collect();
        }

        return PharmacyHour::whereIn('pharmacy_id', $pharmacyIds)
            ->get()
            ->groupBy('pharmacy_id');
    }

    /**
     * هل الصيدلية مفتوحة الآن؟ يُحسب في الـ Backend عبر المنطق الموجود
     * (PharmacyAvailability) — لا يُترك للعميل ولا يُكرَّر هنا.
     *
     * PharmacyAvailability يقرأ ساعات العمل من علاقة `hours` فقط، لذا نمرّرها
     * على كائن Pharmacy خفيف بلا أي استعلام إضافي.
     *
     * @param  Collection<int, PharmacyHour>  $hours
     */
    private function isOpenNow(Collection $hours, Carbon $now): bool
    {
        if ($hours->isEmpty()) {
            return false;
        }

        $pharmacy = new Pharmacy;
        $pharmacy->setRelation('hours', $hours);

        return PharmacyAvailability::isOpenNow($pharmacy, $now);
    }

    /**
     * ترتيب حتمي بالكامل. عند غياب الإحداثيات تصبح المسافة null وتُعامَل كالأسوأ.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    private function sort(array &$results, string $sort): void
    {
        // PHP 8+ sort مستقر: التعادل يحافظ على الترتيب الأصلي (id تصاعدي).
        usort($results, function (array $a, array $b) use ($sort): int {
            $distanceA = $a['distance_km'] ?? PHP_FLOAT_MAX;
            $distanceB = $b['distance_km'] ?? PHP_FLOAT_MAX;

            if ($sort === self::SORT_CHEAPEST) {
                return $a['price'] <=> $b['price']
                    ?: $distanceA <=> $distanceB;
            }

            if ($sort === self::SORT_BEST) {
                $rankA = StockStatus::rank($a['availability']);
                $rankB = StockStatus::rank($b['availability']);

                return $rankA <=> $rankB
                    ?: $distanceA <=> $distanceB
                    ?: $a['price'] <=> $b['price']
                    ?: ($b['rating'] ?? 0.0) <=> ($a['rating'] ?? 0.0);
            }

            // nearest (الافتراضي)
            return $distanceA <=> $distanceB
                ?: $a['price'] <=> $b['price'];
        });
    }
}
