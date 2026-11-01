<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RatingRequest;
use App\Http\Requests\Api\RatingUpdateRequest;
use App\Http\Resources\RatingResource;
use App\Models\Pharmacy;
use App\Models\Rating;
use App\Models\Order;
use App\Models\SubOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        abort_unless($request->user()->role === 'patient', 403);

        $data = $request->validated();
        $pharmacy = Pharmacy::findOrFail($data['pharmacy_id']);

        abort_unless($pharmacy->is_active, 403, 'الصيدلية غير نشطة');

        // Keep the existing one-rating-per-patient/pharmacy rule. A completed
        // parent alone is insufficient when that pharmacy's slice was cancelled.
        $eligible = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->whereDoesntHave('subOrders', function ($subOrders) {
                $subOrders->whereNotIn('status', [SubOrder::STATUS_DELIVERED, SubOrder::STATUS_CANCELLED]);
            })
            ->where(function ($query) use ($pharmacy) {
                $query->whereHas('subOrders', function ($subOrders) use ($pharmacy) {
                    $subOrders->where('pharmacy_id', $pharmacy->id)
                        ->where('status', SubOrder::STATUS_DELIVERED);
                })->orWhere(function ($legacy) use ($pharmacy) {
                    $legacy->whereDoesntHave('subOrders')->where('pharmacy_id', $pharmacy->id);
                });
            })->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'pharmacy_id' => ['يمكن تقييم الصيدلية بعد استلام طلب مكتمل منها فقط.'],
            ]);
        }

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

    public function show(Request $request, Rating $rating): JsonResponse
    {
        $this->authorizeOwner($request, $rating);

        $rating->load('user');

        return response()->json([
            'success' => true,
            'message' => 'تم جلب التقييم بنجاح',
            'data' => new RatingResource($rating),
        ]);
    }

    public function update(RatingUpdateRequest $request, Rating $rating): JsonResponse
    {
        $this->authorizeOwner($request, $rating);

        $rating->update([
            'stars_rating' => $request->validated()['stars_rating'],
            'comment' => $request->validated()['comment'] ?? $rating->comment,
        ]);

        $rating->load('user');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التقييم بنجاح',
            'data' => new RatingResource($rating),
        ]);
    }

    public function destroy(Request $request, Rating $rating): JsonResponse
    {
        $this->authorizeOwner($request, $rating);

        $rating->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التقييم بنجاح',
        ]);
    }

    private function authorizeOwner(Request $request, Rating $rating): void
    {
        abort_unless($rating->user_id === $request->user()->id, 404);
    }

}
