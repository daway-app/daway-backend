<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Support\Haversine;
use App\Support\Image;
use App\Support\PharmacyAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicineController extends Controller
{
    /**
     * H9: أقصى طول لقيمة البحث داخل مفتاح الـ cache — يمنع تضخم المفاتيح
     * (cache key explosion) عند إرسال استعلامات ضخمة أو تكرارية.
     */
    private const MAX_CACHE_QUERY_LENGTH = 100;

    /**
     * عرض كتالوج أدوية وزارة الصحة (مع بحث اختياري).
     */
    public function index(Request $request): JsonResponse
    {
        $catVer = $this->catalogVersion();

        $query = MohMedicine::query();

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) >= 2) {
            SearchLog::track($q, 'api');

            $query->where(function ($builder) use ($q) {
                $this->fulltextOrLike($builder, ['trade_name', 'generic_name', 'manufacturer', 'company'], $q);
            });
        }

        $validated = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) $request->get('page', 1);

        $items = Cache::remember($this->cacheKey("api_meds_idx|v{$catVer}", $q)."|{$page}|{$perPage}", 900, function () use ($query, $perPage) {
            return $query->orderBy('trade_name')->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
            'data' => collect($items->items())->map(fn (MohMedicine $m) => $this->mohPayload($m)),
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * بحث عن دواء (الكتالوج العام + كتالوج وزارة الصحة) مع توفر الصيدليات.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'success' => true,
                'message' => 'تم البحث بنجاح',
                'data' => [
                    'medicines' => [],
                    'moh_catalog' => [],
                ],
            ]);
        }

        SearchLog::track($q, 'api');

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $medVer = $this->medicinesVersion();
        $catVer = $this->catalogVersion();

        $geoTag = $hasGeo
            ? sprintf('|geo|%.4f|%.4f|%d', $lat, $lng, $radiusKm)
            : '|geo|none';

        $result = Cache::remember($this->cacheKey("api_meds_search|v{$medVer}|v{$catVer}{$geoTag}", $q), 900, function () use ($q, $lat, $lng, $radiusKm, $hasGeo) {
            $medicineQuery = Medicine::query();
            $this->fulltextOrLike($medicineQuery, ['trade_name', 'active_ingredient'], $q);
            $medicines = $medicineQuery->limit(10)->get();

            $mohQuery = MohMedicine::query();
            $this->fulltextOrLike($mohQuery, ['trade_name', 'generic_name'], $q);
            $mohMedicines = $mohQuery->limit(20)->get();

            $medicinesPayload = $medicines->map(function (Medicine $m) use ($lat, $lng, $radiusKm, $hasGeo) {
                $payload = $this->medicinePayload($m);
                $payload['available_pharmacies_count'] = $this->availablePharmaciesCount($m->id, $lat, $lng, $radiusKm, $hasGeo);
                $payload['nearest_pharmacy'] = $hasGeo
                    ? $this->nearestPharmacyFor($m->id, $lat, $lng, $radiusKm)
                    : null;

                return $payload;
            })->all();

            return [
                'medicines' => $medicinesPayload,
                'moh_catalog' => $mohMedicines->map(fn (MohMedicine $m) => $this->mohPayload($m))->all(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'تم البحث بنجاح',
            'data' => [
                'medicines' => $result['medicines'],
                'moh_catalog' => $result['moh_catalog'],
            ],
        ]);
    }

    /**
     * تفاصيل دواء من الكتالوج العام مع الصيدليات المتوفرة.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $medicine = Medicine::with('pharmacyMedicines.pharmacy')->findOrFail($id);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $payload = $this->medicinePayload($medicine, true);

        if ($hasGeo) {
            $payload['nearest_pharmacy'] = $this->nearestPharmacyFor($medicine->id, $lat, $lng, $radiusKm);
        } else {
            $payload['nearest_pharmacy'] = null;
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الدواء بنجاح',
            'data' => $payload,
        ]);
    }

    /**
     * البحث عن دواء حسب المادة الفعالة.
     */
    public function byActiveIngredient(string $ingredient): JsonResponse
    {
        $medVer = $this->medicinesVersion();

        $medicines = Cache::remember("api_meds_active|v{$medVer}|{$ingredient}", 900, function () use ($ingredient) {
            $query = Medicine::query();
            $this->fulltextOrLike($query, ['active_ingredient'], $ingredient);

            return $query->orderBy('trade_name')
                ->limit(20)
                ->get();
        });

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الأدوية بنجاح',
            'data' => $medicines->map(fn (Medicine $m) => $this->medicinePayload($m)),
        ]);
    }

    /**
     * بدائل دواء بنفس المادة الفعّالة.
     * الـSRS: medicine, image, price, pharmacy, distance, availability.
     * الـhelper `Medicine::alternativesByActiveIngredient` يستثني الـid المعطى ويحدّ بـ 10.
     */
    public function alternatives(Request $request, string $id): JsonResponse
    {
        $medicine = Medicine::findOrFail($id);

        if (! $medicine->active_ingredient) {
            return response()->json([
                'success' => true,
                'message' => 'لا توجد بدائل بنفس المادة الفعالة',
                'data' => [],
            ]);
        }

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radius = $validated['radius_km'] ?? null;

        $alternatives = Medicine::alternativesByActiveIngredient(
            $medicine->active_ingredient,
            $medicine->id,
        )->get();

        $payload = $alternatives->map(function (Medicine $alt) use ($lat, $lng, $radius) {
            $nearestPharmacy = $lat !== null && $lng !== null
                ? $this->nearestPharmacyFor($alt, $lat, $lng, $radius)
                : null;

            $row = $this->medicinePayload($alt);
            $row['nearest_pharmacy'] = $nearestPharmacy;

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب البدائل بنجاح',
            'data' => $payload,
        ]);
    }

    /**
     * الصيدليات التي يتوفر بها دواء معين.
     */
    public function pharmacies(Request $request, string $id): JsonResponse
    {
        $medicine = Medicine::findOrFail($id);

        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);
        $hasGeo = $lat !== null && $lng !== null;

        $query = PharmacyMedicine::query()
            ->where('medicine_id', $medicine->id)
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->with(['pharmacy' => function ($q) {
                $q->with('hours');
            }]);

        $available = $query->get()->map(function (PharmacyMedicine $pm) use ($lat, $lng, $radiusKm, $hasGeo) {
            $row = $this->pharmacyRowPayload($pm, $lat, $lng, $radiusKm);

            if ($hasGeo && $row['distance_km'] !== null && $row['distance_km'] > $radiusKm) {
                return null;
            }

            return $row;
        })->filter()->values();

        if ($hasGeo) {
            $available = $available->sortBy('distance_km')->values();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الصيدليات المتوفرة بنجاح',
            'data' => $available,
        ]);
    }

    private function catalogVersion(): int
    {
        return (int) Cache::get('med_catalog_version', 1);
    }

    private function medicinesVersion(): int
    {
        return (int) Cache::get('med_medicines_version', 1);
    }

    /**
     * H9: يبني مفتاح cache آمن من مدخلات المستخدم:
     *  - يحذف المسافات الزائدة ويوحّد الـ whitespace.
     *  - يقصّ الطول إلى MAX_CACHE_QUERY_LENGTH.
     *  - يستبدل فاصل المفتاح (|) لمنع تصادم/تلاعب بمفاتيح الـ cache.
     */
    private function cacheKey(string $prefix, string $query): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        $normalized = mb_substr($normalized, 0, self::MAX_CACHE_QUERY_LENGTH);
        $normalized = str_replace('|', ' ', $normalized);

        return $prefix.'|'.$normalized;
    }

    /**
     * بحث FULLTEXT على MySQL (إن توفرت الفهارس) مع تراجع إلى LIKE على باقي الأنظمة.
     */
    private function fulltextOrLike(Builder $query, array $columns, string $value): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->whereRaw('MATCH('.implode(',', $columns).') AGAINST (? IN BOOLEAN MODE)', [str_replace(' ', '* ', $value).'*']);

            return;
        }

        $query->where($columns[0], 'like', "%{$value}%");
        foreach (array_slice($columns, 1) as $column) {
            $query->orWhere($column, 'like', "%{$value}%");
        }
    }

    private function mohPayload(MohMedicine $m): array
    {
        return [
            'id' => $m->id,
            'trade_name' => $m->trade_name,
            'generic_name' => $m->generic_name,
            'manufacturer' => $m->manufacturer,
            'dosage_form' => $m->dosage_form,
            'product_class' => $m->product_class,
            'origin' => $m->origin,
            'official_price' => $m->official_price !== null ? (float) $m->official_price : null,
            'packaging' => $m->packaging,
            'company' => $m->company,
            'availability' => $m->availability,
            'price_updated_at' => $m->price_updated_at?->toDateString(),
        ];
    }

    private function medicinePayload(Medicine $m, bool $withPharmacies = false): array
    {
        $payload = [
            'id' => $m->id,
            'trade_name' => $m->trade_name,
            'active_ingredient' => $m->active_ingredient,
            'description' => $m->description,
            'image_url' => Image::url($m->image),
            'is_available' => $m->is_available,
        ];

        if ($withPharmacies) {
            $payload['pharmacies'] = $m->pharmacyMedicines
                ->filter(fn ($pm) => $pm->is_available && $pm->quantity > 0)
                ->map(fn ($pm) => [
                    'pharmacy_id' => $pm->pharmacy->id,
                    'pharmacy_name' => $pm->pharmacy->pharmacy_name,
                    'price' => (float) $pm->price,
                    'quantity' => $pm->quantity,
                ])
                ->values();
        }

        return $payload;
    }

    /**
     * يبني صفّ صيدلية واحد متوافقاً مع عقد SRS:
     * pharmacy_id, name, price, quantity, availability_status,
     * distance_km, phone, latitude, longitude, working_hours, rating.
     */
    private function pharmacyRowPayload(
        PharmacyMedicine $pm,
        ?float $lat,
        ?float $lng,
        int $radiusKm
    ): array {
        $pharmacy = $pm->pharmacy;
        $hasGeo = $lat !== null && $lng !== null
            && $pharmacy->latitude !== null
            && $pharmacy->longitude !== null;

        $distance = null;
        if ($hasGeo) {
            $distance = round(
                Haversine::kmBetween($lat, $lng, (float) $pharmacy->latitude, (float) $pharmacy->longitude),
                2
            );
        }

        $isActive = (bool) ($pharmacy->is_active ?? true);

        return [
            'pharmacy_id' => $pharmacy->id,
            'name' => $pharmacy->pharmacy_name,
            'price' => (float) $pm->price,
            'quantity' => (int) $pm->quantity,
            'availability_status' => $this->availabilityStatus($pm, $isActive),
            'distance_km' => $distance,
            'phone' => $pharmacy->phone_number,
            'latitude' => $pharmacy->latitude !== null ? (float) $pharmacy->latitude : null,
            'longitude' => $pharmacy->longitude !== null ? (float) $pharmacy->longitude : null,
            'working_hours' => $this->workingHoursPayload($pharmacy),
            'rating' => $pharmacy->avg_rating !== null ? round((float) $pharmacy->avg_rating, 1) : null,
        ];
    }

    /**
     * اشتقاق availability_status وفق عتبة المخزون المنخفض الثابتة.
     * القيم المسموحة: available / low_stock / out_of_stock.
     */
    private function availabilityStatus(PharmacyMedicine $pm, bool $pharmacyActive): string
    {
        if (! $pm->is_available || $pm->quantity <= 0 || ! $pharmacyActive) {
            return 'out_of_stock';
        }

        if ($pm->quantity <= PharmacyMedicine::LOW_STOCK_THRESHOLD) {
            return 'low_stock';
        }

        return 'available';
    }

    /**
     * صياغة ساعات العمل كقائمة موحّدة وفق عقد SRS.
     */
    private function workingHoursPayload(Pharmacy $pharmacy): array
    {
        return $pharmacy->hours
            ->map(fn ($h) => [
                'day_of_week' => $h->day_of_week,
                'open_time' => $h->open_time,
                'close_time' => $h->close_time,
                'is_closed' => (bool) $h->is_closed,
            ])
            ->values()
            ->all();
    }

    /**
     * عدد الصيدليات المميّزة التي يتوفر لديها هذا الدواء متاحاً وفي المخزون.
     * إذا تُقدّمت إحداثيات + نصف قطر، تُقيَّد النتيجة بنطاق radius_km.
     */
    private function availablePharmaciesCount(
        int $medicineId,
        ?float $lat,
        ?float $lng,
        int $radiusKm,
        bool $hasGeo
    ): int {
        $base = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->where('pm.medicine_id', $medicineId)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true);

        if ($hasGeo) {
            $rows = $base
                ->select('p.latitude as lat', 'p.longitude as lng')
                ->get();

            $count = 0;
            foreach ($rows as $r) {
                if ($r->lat === null || $r->lng === null) {
                    continue;
                }
                $d = Haversine::kmBetween($lat, $lng, (float) $r->lat, (float) $r->lng);
                if ($d <= $radiusKm) {
                    $count++;
                }
            }

            return $count;
        }

        return (int) $base->distinct()->count('p.id');
    }

    /**
     * أقرب صيدلية فيها الدواء متاح وفي المخزون، مع مراعاة نصف القطر.
     * يُعيد null إذا لم تتوفر إحداثيات أو لا توجد نتيجة ضمن النطاق.
     */
    private function nearestPharmacyFor(
        int $medicineId,
        float $lat,
        float $lng,
        int $radiusKm
    ): ?array {
        $rows = DB::table('pharmacy_medicines as pm')
            ->join('pharmacies as p', 'p.id', '=', 'pm.pharmacy_id')
            ->where('pm.medicine_id', $medicineId)
            ->where('pm.is_available', true)
            ->where('pm.quantity', '>', 0)
            ->where('p.is_active', true)
            ->whereNotNull('p.latitude')
            ->whereNotNull('p.longitude')
            ->select('p.id', 'p.pharmacy_name', 'p.latitude as lat', 'p.longitude as lng')
            ->get();

        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($rows as $r) {
            $d = Haversine::kmBetween($lat, $lng, (float) $r->lat, (float) $r->lng);
            if ($d <= $radiusKm && $d < $bestDistance) {
                $bestDistance = $d;
                $best = $r;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'id' => (int) $best->id,
            'name' => $best->pharmacy_name,
            'distance_km' => round($bestDistance, 2),
            'availability_status' => 'available',
        ];
    }
}
