<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Support\Haversine;
use App\Support\Image;
use App\Support\PharmacyAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PharmacyController extends Controller
{
    /**
     * قائمة الصيدليات النشطة مع بحث اختياري وفلترة حسب الدواء المتوفر.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:50',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $hasGeo = $request->filled('latitude') && $request->filled('longitude');
        $lat = $hasGeo ? (float) $validated['latitude'] : null;
        $lng = $hasGeo ? (float) $validated['longitude'] : null;
        $radiusKm = (int) ($validated['radius_km'] ?? 15);

        $query = Pharmacy::query()
            ->where('is_active', true)
            ->withCount('ratings')
            ->withAvg('ratings', 'stars_rating');

        $search = trim((string) $request->get('pharmacy_name', ''));
        if ($search !== '') {
            $query->where('pharmacy_name', 'like', "%{$search}%");
        }

        if ($request->filled('medicine_id')) {
            $query->whereHas('pharmacyMedicines', function ($builder) use ($request) {
                $builder->where('medicine_id', $request->integer('medicine_id'))
                    ->where('is_available', true)
                    ->where('quantity', '>', 0);
            });
        }

        $items = $query
            ->when($hasGeo && DB::connection()->getDriverName() === 'mysql', function ($q) use ($lat, $lng) {
                // MySQL: استخدم SQL للترتيب/الفلترة حسب distance (أداء أفضل).
                return $q->selectRaw(
                    '*, (6371 * acos(least(1.0, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))) AS distance_km',
                    [$lat, $lng, $lat]
                )
                    ->having('distance_km', '<=', $radiusKm)
                    ->orderBy('distance_km');
            })
            ->when($hasGeo && DB::connection()->getDriverName() !== 'mysql', function ($q) use ($lat, $lng, $radiusKm) {
                // SQLite/drivers آخرون بدون math SQL: فلترة بسيطة على NOT NULL lat/lng.
                return $q->whereNotNull('latitude')
                    ->whereNotNull('longitude');
            })
            ->when(! $hasGeo, fn ($q) => $q->orderBy('pharmacy_name'))
            ->paginate($perPage);

        $rows = collect($items->items())->map(function (Pharmacy $pharmacy) use ($hasGeo, $lat, $lng) {
            $pharmacy->loadMissing('hours');

            $distance = null;
            if ($hasGeo) {
                if (isset($pharmacy->getAttributes()['distance_km'])) {
                    $distance = round((float) $pharmacy->getAttributes()['distance_km'], 3);
                } elseif ($lat !== null && $lng !== null
                    && $pharmacy->latitude !== null && $pharmacy->longitude !== null) {
                    // SQLite وغيره: احسب في PHP باستخدام Haversine.
                    $distance = round(
                        Haversine::kmBetween($lat, $lng, (float) $pharmacy->latitude, (float) $pharmacy->longitude),
                        3
                    );
                }
            }

            return [
                ...$this->payload($pharmacy),
                'distance_km' => $distance,
                'is_open_now' => PharmacyAvailability::isOpenNow($pharmacy),
            ];
        });

        // للـ drivers غير MySQL مع geo: فلترة/ترتيب في PHP بعد الجلب.
        if ($hasGeo && DB::connection()->getDriverName() !== 'mysql') {
            $rows = $rows->filter(fn ($r) => $r['distance_km'] === null || $r['distance_km'] <= $radiusKm)
                ->sortBy('distance_km')
                ->values();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الصيدليات بنجاح',
            'data' => $rows,
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * تفاصيل صيدلية واحدة: ساعات العمل + التقييمات + الأدوية المتوفرة.
     * C3: الصيدليات غير النشطة لا تُكشف للموبايل — حماية من تسريب بيانات
     * صيدليات معطّلة (ToS violation) وعناوين/تقييمات مرتبطة بها.
     */
    public function show(int $id): JsonResponse
    {
        $pharmacy = Pharmacy::with([
            'hours',
            'ratings.user',
            'pharmacyMedicines.medicine',
        ])->where('is_active', true)->find($id);

        if (! $pharmacy) {
            return response()->json([
                'success' => false,
                'message' => 'الصيدلية غير موجودة',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل الصيدلية بنجاح',
            'data' => [
                ...$this->payload($pharmacy),
                'is_open_now' => PharmacyAvailability::isOpenNow($pharmacy),
                'hours' => $pharmacy->hours->map(fn ($hour) => [
                    'day_of_week' => $hour->day_of_week,
                    'open_time' => $hour->open_time?->format('H:i'),
                    'close_time' => $hour->close_time?->format('H:i'),
                    'is_closed' => (bool) $hour->is_closed,
                ])->values(),
                'ratings' => $pharmacy->ratings->map(fn ($rating) => [
                    'id' => $rating->id,
                    'user_name' => $rating->user?->name,
                    'stars_rating' => (int) $rating->stars_rating,
                    'comment' => $rating->comment,
                    'created_at' => $rating->created_at?->toDateTimeString(),
                ])->values(),
                'medicines' => $pharmacy->pharmacyMedicines
                    ->filter(fn ($pm) => $pm->is_available && $pm->quantity > 0)
                    ->map(fn ($pm) => [
                        'price' => (float) $pm->price,
                        'quantity' => $pm->quantity,
                        'medicine' => [
                            'id' => $pm->medicine->id,
                            'trade_name' => $pm->medicine->trade_name,
                            'active_ingredient' => $pm->medicine->active_ingredient,
                            'description' => $pm->medicine->description,
                            'image' => Image::url($pm->medicine->image),
                        ],
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * بيانات آمنة عن الصيدلية (بدون أي أسرار مثل user_id).
     */
    private function payload(Pharmacy $pharmacy): array
    {
        return [
            'id' => $pharmacy->id,
            'pharmacy_name' => $pharmacy->pharmacy_name,
            'address' => $pharmacy->address,
            'latitude' => (float) $pharmacy->latitude,
            'longitude' => (float) $pharmacy->longitude,
            'phone_number' => $pharmacy->phone_number,
            'logo' => Image::url($pharmacy->logo),
            'avg_rating' => $pharmacy->avg_rating !== null ? (float) $pharmacy->avg_rating : null,
            'ratings_count' => $pharmacy->ratings_count ?? null,
            'ratings_avg' => $pharmacy->ratings_avg_stars_rating !== null ? round((float) $pharmacy->ratings_avg_stars_rating, 2) : null,
        ];
    }
}
