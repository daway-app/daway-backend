<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use Carbon\Carbon; // To get pharmacy's own medicines
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

// For handling time and dates

class PharmacyDashboardController extends Controller
{
    /**
     * Display the pharmacy dashboard.
     *
     * @return View
     */
    public function index()
    {
        // Get the authenticated user
        $user = Auth::user();

        // Ensure the logged-in user is a pharmacy user
        if ($user->role !== 'pharmacy') {
            // Redirect or show an error if not a pharmacy user
            return redirect()->route('dashboard')->with('error', __('pharmacy.access_denied'));
        }

        // Load opening hours for the availability check; the rating average is
        // calculated in SQL below so a large ratings collection is not hydrated.
        $pharmacy = Pharmacy::where('user_id', $user->id)
            ->with('hours')
            ->firstOrFail();

        // 1. عدد الأدوية في مخزونه — M-24: استعلام تجميعي واحد بدل 4 counts منفصلة
        $stats = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN quantity > 0 AND quantity <= 10 THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock")
            ->first();

        $totalMedicinesInStock = (int) ($stats->total ?? 0);
        $availableCount = (int) ($stats->available ?? 0);
        $lowStockCount = (int) ($stats->low_stock ?? 0);
        $outOfStockCount = (int) ($stats->out_of_stock ?? 0);
        $lowStockItems = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('quantity', '>', 0)
            ->where('quantity', '<=', 10)
            ->with('medicine')
            ->latest()
            ->take(5)
            ->get();

        // 2. متوسط التقييم
        $averageRating = $pharmacy->ratings()->avg('stars_rating');

        // 3. حالة الصيدلية (مفتوحة/مغلقة)
        $isPharmacyOpen = \App\Support\PharmacyAvailability::isOpenNow($pharmacy);

        // 4. جدول أدوية صيدليته
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine')
            ->latest()
            ->paginate(5); // Paginate for the dashboard table

        // 5. آخر التقييمات الواردة لصيدليته
        $latestRatings = $pharmacy->ratings()->with('user')->latest()->take(5)->get();

        // 6. بيانات مخطط النشاط الأسبوعي (آخر 7 أيام) — M-24: استعلامان GROUP BY
        //    بدل 14 whereDate (غير sargable، لا يستفيدان من أي فهرس)
        $arabicDays = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $weekStart = now()->subDays(6)->startOfDay();

        $ordersByDay = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('created_at', '>=', $weekStart)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day');

        $ratingsByDay = Rating::where('pharmacy_id', $pharmacy->id)
            ->where('created_at', '>=', $weekStart)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day');

        $chartLabels = [];
        $ordersChart = [];
        $ratingsChart = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();

            $chartLabels[] = $arabicDays[date('w', strtotime($day))];
            $ordersChart[] = (int) ($ordersByDay[$day] ?? 0);
            $ratingsChart[] = (int) ($ratingsByDay[$day] ?? 0);
        }

        $chartData = [
            'labels' => $chartLabels,
            'orders' => $ordersChart,
            'ratings' => $ratingsChart,
        ];

        $newInquiries = $pharmacy->patientInquiries()->where('status', 'new')->count();
        $latestInquiries = $pharmacy->patientInquiries()
            ->with(['user', 'medicine'])
            ->latest()
            ->take(5)
            ->get();

        return view('pharmacy.dashboard.index', compact(
            'user',
            'pharmacy',
            'totalMedicinesInStock',
            'availableCount',
            'lowStockCount',
            'outOfStockCount',
            'averageRating',
            'isPharmacyOpen',
            'pharmacyMedicines',
            'latestRatings',
            'chartData',
            'newInquiries',
            'lowStockItems',
            'latestInquiries'
        ));
    }
}
