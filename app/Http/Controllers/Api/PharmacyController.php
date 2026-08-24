<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Support\Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyController extends Controller
{
    /**
     * قائمة الصيدليات النشطة مع بحث اختياري وفلترة حسب الدواء المتوفر.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

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

        $items = $query->orderBy('pharmacy_name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الصيدليات بنجاح',
            'data' => collect($items->items())->map(fn (Pharmacy $pharmacy) => $this->payload($pharmacy)),
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
     */
    public function show(int $id): JsonResponse
    {
        $pharmacy = Pharmacy::with([
            'hours',
            'ratings.user',
            'pharmacyMedicines.medicine',
        ])->find($id);

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
                'hours' => $pharmacy->hours->map(fn ($hour) => [
                    'day_of_week' => $hour->day_of_week,
                    'open_time' => $hour->open_time ? substr((string) $hour->open_time, 0, 5) : null,
                    'close_time' => $hour->close_time ? substr((string) $hour->close_time, 0, 5) : null,
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
