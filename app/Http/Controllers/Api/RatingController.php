<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RatingRequest;
use App\Http\Resources\RatingResource;
use App\Models\Pharmacy;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pharmacy_id' => 'nullable|integer|exists:pharmacies,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Rating::with('user');

        if (! empty($validated['pharmacy_id'])) {
            $query->where('pharmacy_id', $validated['pharmacy_id']);
        } else {
            $query->where('user_id', $request->user()->id);
        }

        $ratings = $query->latest('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب التقييمات بنجاح',
            'data' => RatingResource::collection($ratings->items()),
            'pagination' => [
                'total' => $ratings->total(),
                'per_page' => $ratings->perPage(),
                'current_page' => $ratings->currentPage(),
                'last_page' => $ratings->lastPage(),
            ],
        ]);
    }

    public function store(RatingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);

        abort_unless($pharmacy->is_active, 403, 'الصيدلية غير نشطة');

        $rating = Rating::create([
            'user_id' => $request->user()->id,
            'pharmacy_id' => $data['pharmacy_id'],
            'stars_rating' => $data['stars_rating'],
            'comment' => $data['comment'] ?? null,
            'created_at' => now(),
        ]);

        $rating->load('user');

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة التقييم بنجاح',
            'data' => new RatingResource($rating),
        ], 201);
    }
}
