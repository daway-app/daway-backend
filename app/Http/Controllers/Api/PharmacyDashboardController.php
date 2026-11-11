<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatientInquiry;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use App\Services\PharmacyContext;
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

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id);

        // One database round trip for the four inventory counters, preserving each
        // counter's existing conditions and API response fields.
        $counts = (clone $base)->selectRaw("
            COUNT(*) AS total_medicines,
            SUM(CASE WHEN is_available = 1 AND quantity > 0 THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN quantity > 0 AND quantity <= 10 THEN 1 ELSE 0 END) AS low_count,
            SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_count
        ")->first();

        $totalMedicines = (int) $counts->total_medicines;
        $availableCount = (int) $counts->available_count;
        $lowCount = (int) $counts->low_count;
        $outCount = (int) $counts->out_count;

        $inquiryCounts = $pharmacy->patientInquiries()->selectRaw("
            COUNT(*) AS total_inquiries,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_inquiries
        ", ['new'])->first();

        $pendingInquiries = (int) $inquiryCounts->pending_inquiries;
        $totalInquiries = (int) $inquiryCounts->total_inquiries;

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

        // DEFECT-2 fix: the dashboard's "آخر استفسارات المرضى" card was fed
        // `latest_ratings` instead of inquiries (FRONTEND bug). This block ships
        // the matching field the React `DashboardPage` already expects —
        // `latest_inquiries` — so the card renders real inquiries and the
        // i18n keys (`latest_inquiries`, `inquiry_q`, `no_inquiries_yet`) are
        // no longer dead. Mirrors the Blade `$inquiriesList` contract
        // (dashboard/index.blade.php:121+).
        $latestInquiries = $pharmacy->patientInquiries()
            ->with(['user', 'medicine'])
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->map(fn (PatientInquiry $inquiry) => [
                'id' => $inquiry->id,
                'user_name' => $inquiry->user?->name,
                'medicine_trade_name' => $inquiry->medicine?->trade_name,
                'message' => $inquiry->message,
                'status' => (string) $inquiry->status,
                'created_at' => $inquiry->created_at?->toDateTimeString(),
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
                'latest_inquiries' => $latestInquiries,
                'low_stock_items' => $lowStockItems,
            ],
        ]);
    }
}
