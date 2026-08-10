<?php

namespace App\Http\Controllers\web\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Medicine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $userCounts = User::select(
            DB::raw('SUM(CASE WHEN role = "patient" THEN 1 ELSE 0 END) as totalPatients'),
            DB::raw('SUM(CASE WHEN role IN ("admin", "pharmacy") THEN 1 ELSE 0 END) as totalOtherUsers')
        )->first();

        // Replaced Pharmacy:: with User::where('role', 'pharmacy')->
        $activePharmacies = User::where('role', 'pharmacy')->where('is_active', true)->count();
        $totalMedicines = Medicine::count();

        // Fetch data for charts
        $stockStatus = Medicine::select(
            DB::raw('SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available'),
            DB::raw('SUM(CASE WHEN is_available = 0 AND stock > 0 THEN 1 ELSE 0 END) as low_stock'),
            DB::raw('SUM(CASE WHEN is_available = 0 AND stock = 0 THEN 1 ELSE 0 END) as out_of_stock')
        )->first();

        $userRoles = User::select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        // Fetch recent activities
        // Replaced Pharmacy:: with User::where('role', 'pharmacy')->
        $recentPharmacies = User::where('role', 'pharmacy')->latest()->take(5)->get()->map(function ($pharmacy) {
            return (object)[
                'description' => 'انضمت <strong>' . $pharmacy->name . '</strong> إلى المنصة', // Assuming pharmacy name is in 'name' field
                'time' => $pharmacy->created_at->diffForHumans(),
                'created_at' => $pharmacy->created_at,
                'color' => '#10b981'
            ];
        });

        $recentPatients = User::where('role', 'patient')->latest()->take(5)->get()->map(function ($patient) {
            return (object)[
                'description' => 'سجّل <strong>' . $patient->name . '</strong> حساباً جديداً',
                'time' => $patient->created_at->diffForHumans(),
                'created_at' => $patient->created_at,
                'color' => '#3b82f6'
            ];
        });

        $recentActivities = $recentPharmacies->merge($recentPatients)
            ->sortByDesc('created_at')
            ->take(5);

        // Replaced Pharmacy:: with User::where('role', 'pharmacy')-> and removed orderBy('avg_rating', 'desc')
        $topPharmacies = User::where('role', 'pharmacy')->latest()->take(4)->get(); // Ordered by latest created
        $patients = User::where('role', 'patient')->latest()->take(5)->get(); // Get latest 5 patients

        return view('dashboard.index', [
            'totalPatients' => $userCounts->totalPatients ?? 0,
            'totalOtherUsers' => $userCounts->totalOtherUsers ?? 0,
            'activePharmacies' => $activePharmacies,
            'totalMedicines' => $totalMedicines,
            'stockStatus' => $stockStatus,
            'userRoles' => $userRoles,
            'topPharmacies' => $topPharmacies,
            'recentActivities' => $recentActivities,
            'patients' => $patients
        ]);
    }
}
