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

            return redirect('/')->with('error', 'ليس لديك صلاحية الوصول لهذه الصفحة.');
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

        return view('pharmacy.ratings.index', compact('pharmacy', 'ratings', 'averageRating'));
    }
}
