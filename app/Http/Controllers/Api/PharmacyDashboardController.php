<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PharmacyDashboardController extends Controller
{
    /**
     * إحصائيات لوحة تحكم الصيدلية.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id);

        $totalMedicines = (clone $base)->count();
        $availableCount = (clone $base)->where('is_available', true)->where('quantity', '>', 0)->count();
        $lowCount = (clone $base)->where('quantity', '>', 0)->where('quantity', '<=', 10)->count();
        $outCount = (clone $base)->where('quantity', '<=', 0)->count();

        $pendingInquiries = $pharmacy->patientInquiries()->where('status', 'new')->count();
        $totalInquiries = $pharmacy->patientInquiries()->count();

        $newRatingsThisWeek = $pharmacy->ratings()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $latestRatings = $pharmacy->ratings()
            ->with('user')
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn (Rating $rating) => [
                'id' => $rating->id,
                'user_name' => $rating->user?->name,
                'stars_rating' => (int) $rating->stars_rating,
                'comment' => $rating->comment,
                'created_at' => $rating->created_at?->toDateTimeString(),
            ])
            ->values();

        $lowStockItems = (clone $base)
            ->where('quantity', '>', 0)
            ->where('quantity', '<=', 10)
            ->with('medicine:id,trade_name,active_ingredient')
            ->orderBy('quantity')
            ->limit(5)
            ->get()
            ->map(fn (PharmacyMedicine $pm) => [
                'pharmacy_medicine_id' => $pm->id,
                'medicine_id' => $pm->medicine_id,
                'trade_name' => $pm->medicine?->trade_name,
                'active_ingredient' => $pm->medicine?->active_ingredient,
                'quantity' => (int) $pm->quantity,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب إحصائيات لوحة التحكم بنجاح',
            'data' => [
                'total_medicines' => $totalMedicines,
                'available_count' => $availableCount,
                'low_count' => $lowCount,
                'out_count' => $outCount,
                'pending_inquiries' => $pendingInquiries,
                'total_inquiries' => $totalInquiries,
                'new_ratings_this_week' => $newRatingsThisWeek,
                'latest_ratings' => $latestRatings,
                'low_stock_items' => $lowStockItems,
            ],
        ]);
    }
}
