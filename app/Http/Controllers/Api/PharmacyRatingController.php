<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RatingResource;
use App\Models\Pharmacy;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyRatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        abort_unless($pharmacy, 403);

        $ratings = Rating::with('user')
            ->where('pharmacy_id', $pharmacy->id)
            ->latest('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تقييمات الصيدلية بنجاح',
            'data' => RatingResource::collection($ratings->items()),
            'pagination' => [
                'total' => $ratings->total(),
                'per_page' => $ratings->perPage(),
                'current_page' => $ratings->currentPage(),
                'last_page' => $ratings->lastPage(),
            ],
        ]);
    }
}
