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
        $ratings = $pharmacy->ratings()->with('user')->latest()->paginate(50)->withQueryString(); // Assuming 'user' relationship on Rating model

        // Calculate average rating
        $averageRating = $pharmacy->ratings()->avg('stars_rating');

        // Rating distribution
        // P3: كان 5 استعلامات `count()` داخل حلقة (واحد لكل نجمة) ⇒ استعلام
        // `GROUP BY` واحد. `stars_rating` عمود `unsignedTinyInteger` NOT NULL
        // مع قيد CHECK(1..5) ⇒ مجموع الأعداد = `count()` بالضبط، ولا يمكن أن
        // تسقط أي قيمة خارج النطاق 1..5.
        $starCounts = $pharmacy->ratings()
            ->selectRaw('stars_rating, COUNT(*) as c')
            ->groupBy('stars_rating')
            ->pluck('c', 'stars_rating');

        $totalRatings = (int) $starCounts->sum();
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = (int) ($starCounts[$i] ?? 0);
            $distribution[] = [
                'stars' => $i,
                'count' => $count,
                'percent' => $totalRatings > 0 ? round(($count / $totalRatings) * 100) : 0,
            ];
        }

        // Monthly trend (last 6 months)
        // P3: كان 6 استعلامات `avg()` داخل حلقة (سنة+شهر لكل شهر) ⇒ استعلام واحد.
        //
        // ⚠️ لماذا تجميع شرطي بمدى تاريخ، وليس `GROUP BY YEAR()/MONTH()`:
        // `YEAR()`/`MONTH()` دالتان خاصّتان بـMySQL، ومجموعة الاختبار تعمل على
        // SQLite (phpunit.xml) ⇒ تفشل الصفحة بـ`no such function: YEAR` (500).
        // مقارنة النطاق (`created_at >= ? AND < ?`) معيارية وتعمل على المحرّكين
        // بنفس الدلالة تمامًا، وهي أيضًا قابلة لاستخدام فهرس `created_at`.
        $now = now();
        $windows = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = $now->copy()->subMonths($i)->startOfMonth();
            $windows[] = [$start, $start->copy()->addMonth()];
        }

        $selects = [];
        $bindings = [];
        foreach ($windows as $idx => [$start, $end]) {
            $selects[] = "SUM(CASE WHEN created_at >= ? AND created_at < ? THEN stars_rating END) as sum_{$idx}";
            $selects[] = "COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) as cnt_{$idx}";
            array_push($bindings, $start, $end, $start, $end);
        }

        // نطاق خارجي يحدّ الصفوف الممسوحة: خارج الأشهر الستة لا يُسهم أي صف في
        // أي نافذة، فحذفه لا يغيّر الناتج.
        $trendRow = $pharmacy->ratings()
            ->where('created_at', '>=', $windows[0][0])
            ->where('created_at', '<', $windows[5][1])
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();
        $trendAgg = $trendRow ? $trendRow->getAttributes() : [];

        $trendLabels = [];
        $trendData = [];
        foreach ($windows as $idx => [$start, $end]) {
            $trendLabels[] = $start->format('M');
            // نفس فحص الصدق السابق (`$avg ? … : 0`) حرفيًا — حتى يبقى الناتج
            // عددًا صحيحًا `0` عند غياب البيانات، لا `0.0`.
            $count = (int) ($trendAgg['cnt_'.$idx] ?? 0);
            $avg = $count > 0 ? ((float) ($trendAgg['sum_'.$idx] ?? 0)) / $count : null;
            $trendData[] = $avg ? round($avg, 1) : 0;
        }

        return view('pharmacy.ratings.index', compact('pharmacy', 'ratings', 'averageRating', 'distribution', 'totalRatings', 'trendLabels', 'trendData'));
    }
}
