<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PharmacyRatingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Ensure user is authenticated
        // Add middleware to check if the user is a pharmacy
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    /**
     * Display a listing of the pharmacy's ratings and reviews.
     *
     * @return Response
     */
    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Load ratings for the pharmacy
        $ratings = $pharmacy->ratings()->with('user')->latest()->paginate(10); // Assuming 'user' relationship on Rating model

        // Calculate average rating
        $averageRating = $pharmacy->ratings()->avg('stars_rating');

        // Rating distribution
        $totalRatings = $pharmacy->ratings()->count();
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = $pharmacy->ratings()->where('stars_rating', $i)->count();
            $distribution[] = [
                'stars' => $i,
                'count' => $count,
                'percent' => $totalRatings > 0 ? round(($count / $totalRatings) * 100) : 0,
            ];
        }

        // Monthly trend (last 6 months)
        $trendLabels = [];
        $trendData = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $trendLabels[] = $month->format('M');
            $avg = $pharmacy->ratings()
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->avg('stars_rating');
            $trendData[] = $avg ? round($avg, 1) : 0;
        }

        return view('pharmacy.ratings.index', compact('pharmacy', 'ratings', 'averageRating', 'distribution', 'totalRatings', 'trendLabels', 'trendData'));
    }
}
