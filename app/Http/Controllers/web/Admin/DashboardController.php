<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\SearchLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // مفتاح الكاش يشمل اللغة لأن البيانات تحتوي نصوصاً مترجمة (تسميات الرسوم البيانية)
        $stats = Cache::remember('dashboard_stats_'.app()->getLocale(), 60, function () {
            $now = now();

            // Single query: per-role totals + active counts (replaces 3 queries)
            $roleStats = User::select(
                'role',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            )->groupBy('role')->get();

            $totalPatients = 0;
            $totalOtherUsers = 0;
            $activePharmacies = 0;
            $userRoles = [];
            foreach ($roleStats as $row) {
                $userRoles[$row->role] = $row->total;
                if ($row->role === 'patient') {
                    $totalPatients = $row->total;
                } else {
                    $totalOtherUsers += $row->total;
                }
                if ($row->role === 'pharmacy') {
                    $activePharmacies = $row->active;
                }
            }

            // Single query: medicine counts + stock status (replaces 2 queries)
            $medicineStats = Medicine::select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available'),
                DB::raw('SUM(CASE WHEN is_available = 0 AND stock > 0 THEN 1 ELSE 0 END) as low_stock'),
                DB::raw('SUM(CASE WHEN is_available = 0 AND stock = 0 THEN 1 ELSE 0 END) as out_of_stock')
            )->first();

            // Real trends for the stat cards
            $patientsThisMonth = User::where('role', 'patient')->where('created_at', '>=', $now->copy()->startOfMonth())->count();
            $patientsLastMonth = User::where('role', 'patient')
                ->whereBetween('created_at', [$now->copy()->subMonth()->startOfMonth(), $now->copy()->startOfMonth()])
                ->count();
            $trendPatients = $patientsLastMonth > 0
                ? round((($patientsThisMonth - $patientsLastMonth) / $patientsLastMonth) * 100)
                : ($patientsThisMonth > 0 ? 100 : 0);

            $newPharmaciesThisWeek = User::where('role', 'pharmacy')->where('created_at', '>=', $now->copy()->startOfWeek())->count();
            $medicinesAddedRecently = Medicine::where('created_at', '>=', $now->copy()->subDays(30))->count();

            // Weekly series (oldest → newest, 8 weeks) for the activity chart
            // Week start follows the locale: Saturday for Arabic, Sunday for English
            \Carbon\Carbon::setLocale(app()->getLocale());
            $weekStart = app()->getLocale() === 'ar' ? \Carbon\Carbon::SATURDAY : \Carbon\Carbon::SUNDAY;
            $chartSearches = [];
            $chartUsers = [];
            $chartMedicines = [];
            $chartPharmacies = [];
            $chartAll = [];
            $weekLabels = [];
            for ($i = 7; $i >= 0; $i--) {
                $start = $now->copy()->subWeeks($i)->startOfWeek($weekStart);
                $end = $now->copy()->subWeeks($i - 1)->startOfWeek($weekStart);
                $weekUsers = User::where('role', 'patient')->whereBetween('created_at', [$start, $end])->count();
                $weekMedicines = Medicine::whereBetween('created_at', [$start, $end])->count();
                $weekSearches = SearchLog::whereBetween('created_at', [$start, $end])->count();
                $weekPharmacies = User::where('role', 'pharmacy')->whereBetween('created_at', [$start, $end])->count();
                $chartUsers[] = $weekUsers;
                $chartMedicines[] = $weekMedicines;
                $chartSearches[] = $weekSearches;
                $chartPharmacies[] = $weekPharmacies;
                $chartAll[] = $weekSearches + $weekUsers + $weekMedicines + $weekPharmacies;
                $weekLabels[] = $start->translatedFormat('M j').' – '.$end->copy()->subDay()->translatedFormat('M j');
            }

            // نسبة النمو: null عندما لا توجد قيمة سابقة للمقارنة (لا +100% مضللة)
            $pctChange = function (array $series) {
                $last = count($series) > 0 ? $series[count($series) - 1] : 0;
                $prev = count($series) >= 2 ? $series[count($series) - 2] : 0;
                if ($prev <= 0) {
                    return null;
                }

                return round((($last - $prev) / $prev) * 100);
            };

            $fmtTrend = function (array $series) use ($pctChange) {
                $last = count($series) > 0 ? $series[count($series) - 1] : 0;
                $pct = $pctChange($series);
                if ($pct === null) {
                    return $last > 0 ? '▲ +'.$last.' '.__('dashboard.new_this_week') : '0%';
                }

                return ($pct >= 0 ? '▲ +' : '▼ ').abs($pct).'%';
            };

            $toChartDataset = function (array $series, string $label, string $color) use ($pctChange, $fmtTrend) {
                $total = array_sum($series);

                return [
                    'label' => $label,
                    'values' => $series,
                    'total' => $total,
                    'change' => $pctChange($series),
                    'average' => round($total / 8),
                    'color' => $color,
                    'trend' => $fmtTrend($series),
                ];
            };

            $chartDatasets = [
                'all' => $toChartDataset($chartAll, __('dashboard.all_filter'), '#0B8FAC'),
                'searches' => $toChartDataset($chartSearches, __('dashboard.medicines_search_filter'), '#3b82f6'),
                'patients' => $toChartDataset($chartUsers, __('dashboard.users_filter'), '#10b981'),
                'pharmacies' => $toChartDataset($chartPharmacies, __('dashboard.pharmacies_filter'), '#f59e0b'),
            ];

            $chartData = [
                'labels' => $weekLabels,
                'datasets' => $chartDatasets,
            ];

            return [
                'totalPatients' => $totalPatients,
                'totalOtherUsers' => $totalOtherUsers,
                'activePharmacies' => $activePharmacies,
                'totalMedicines' => $medicineStats->total ?? 0,
                'stockStatus' => (object) [
                    'available' => $medicineStats->available ?? 0,
                    'low_stock' => $medicineStats->low_stock ?? 0,
                    'out_of_stock' => $medicineStats->out_of_stock ?? 0,
                ],
                'userRoles' => $userRoles,
                'trendPatients' => $trendPatients,
                'newPharmaciesThisWeek' => $newPharmaciesThisWeek,
                'medicinesAddedRecently' => $medicinesAddedRecently,
                'chartData' => $chartData,
            ];
        });

        // Single UNION query: latest pharmacies + latest patients (replaces 2 queries)
        // مفتاح الكاش يشمل اللغة حتى لا تعرض الأنشطة مترجمة بلغة خاطئة عند تبديل اللغة
        $recentActivities = Cache::remember('dashboard_recent_activities_'.app()->getLocale(), 30, function () {
            $recentPharmacies = User::select('name', 'role', 'created_at')
                ->where('role', 'pharmacy')
                ->latest()
                ->take(5);

            $activities = User::select('name', 'role', 'created_at')
                ->where('role', 'patient')
                ->latest()
                ->take(5)
                ->union($recentPharmacies)
                ->get()
                ->map(function ($item) {
                    $role = is_object($item) ? ($item->role ?? null) : null;
                    if ($role === 'pharmacy') {
                        return [
                            'description' => __('dashboard.pharmacy_joined', ['name' => e($item->name ?? '')]),
                            'time' => $item->created_at ? $item->created_at->diffForHumans() : '',
                            'created_at' => $item->created_at ? $item->created_at->toIso8601String() : '',
                            'color' => '#10b981',
                        ];
                    }

                    return [
                        'description' => __('dashboard.patient_registered', ['name' => e($item->name ?? '')]),
                        'time' => $item->created_at ? $item->created_at->diffForHumans() : '',
                        'created_at' => $item->created_at ? $item->created_at->toIso8601String() : '',
                        'color' => '#3b82f6',
                    ];
                })
                ->sortByDesc('created_at')
                ->take(5);

            return $activities->map(function ($a) {
                return [
                    'description' => (string) ($a['description'] ?? ''),
                    'time' => (string) ($a['time'] ?? ''),
                    'created_at' => (string) ($a['created_at'] ?? ''),
                    'color' => (string) ($a['color'] ?? '#0B8FAC'),
                ];
            })->values()->all();
        });

        // تحصين: مهما كان نوع العناصر المخزنة (كائن/مصفوفة/نص) حوّلها لكائنات آمنة
        $recentActivities = collect($recentActivities)->map(function ($a) {
            if (is_array($a)) {
                return (object) [
                    'description' => (string) ($a['description'] ?? ''),
                    'time' => (string) ($a['time'] ?? ''),
                    'color' => (string) ($a['color'] ?? '#0B8FAC'),
                ];
            }
            if (is_object($a)) {
                return (object) [
                    'description' => (string) ($a->description ?? ''),
                    'time' => (string) ($a->time ?? ''),
                    'color' => (string) ($a->color ?? '#0B8FAC'),
                ];
            }

            return (object) ['description' => (string) $a, 'time' => '', 'color' => '#0B8FAC'];
        })->values();

        $topPharmacies = User::where('role', 'pharmacy')->latest()->take(4)->get();
        $patients = User::where('role', 'patient')->latest()->take(5)->get();

        return view('dashboard.index', array_merge($stats, [
            'recentActivities' => $recentActivities,
            'topPharmacies' => $topPharmacies,
            'patients' => $patients,
        ]));
    }
}
