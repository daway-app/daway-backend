<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use Carbon\Carbon; // To get pharmacy's own medicines
use Illuminate\Support\Facades\Auth;
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

        // 1. عدد الأدوية في مخزونه
        $totalMedicinesInStock = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count();
        $availableCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('quantity', '>', 0)->count();
        $lowStockCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('quantity', '>', 0)->where('quantity', '<=', 10)->count();
        $outOfStockCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('quantity', '<=', 0)->count();
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
        $isPharmacyOpen = $this->checkIfPharmacyIsOpen($pharmacy);

        // 4. جدول أدوية صيدليته
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine')
            ->latest()
            ->paginate(5); // Paginate for the dashboard table

        // 5. آخر التقييمات الواردة لصيدليته
        $latestRatings = $pharmacy->ratings()->with('user')->latest()->take(5)->get();

        // 6. بيانات مخطط النشاط الأسبوعي (آخر 7 أيام):
        //    - orders: الأدوية المضافة إلى المخزون في ذلك اليوم
        //    - ratings: التقييمات الواردة في ذلك اليوم
        $arabicDays = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $chartLabels = [];
        $ordersChart = [];
        $ratingsChart = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();

            $chartLabels[] = $arabicDays[date('w', strtotime($day))];
            $ordersChart[] = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                ->whereDate('created_at', $day)
                ->count();
            $ratingsChart[] = Rating::where('pharmacy_id', $pharmacy->id)
                ->whereDate('created_at', $day)
                ->count();
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

    /**
     * Helper function to check if the pharmacy is currently open.
     */
    private function checkIfPharmacyIsOpen(Pharmacy $pharmacy): bool
    {
        $now = Carbon::now();
        $today = $now->dayName; // e.g., "Monday", "Tuesday"

        // Map Carbon's day names to your stored day_of_week keys (e.g., 'Monday' to 'Monday')
        // Ensure your PharmacyHour model stores day_of_week in English or adjust mapping
        $dayOfWeekMap = [
            'Sunday' => 'Sunday',
            'Monday' => 'Monday',
            'Tuesday' => 'Tuesday',
            'Wednesday' => 'Wednesday',
            'Thursday' => 'Thursday',
            'Friday' => 'Friday',
            'Saturday' => 'Saturday',
        ];

        $currentDayKey = $dayOfWeekMap[$today] ?? null;

        if (! $currentDayKey) {
            return false; // Could not determine current day
        }

        $todayHours = $pharmacy->hours->firstWhere('day_of_week', $currentDayKey);

        if (! $todayHours || $todayHours->is_closed) {
            return false; // Pharmacy is closed today or no hours set
        }

        $openTime = Carbon::parse($todayHours->open_time);
        $closeTime = Carbon::parse($todayHours->close_time);

        // Check if current time is between open and close times
        return $now->between($openTime, $closeTime);
    }
}
