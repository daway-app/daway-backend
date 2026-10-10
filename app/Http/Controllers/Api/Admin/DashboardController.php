<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * لوحة الأدمن — Dashboard (JSON).
 *
 * منطق الحساب منقول حرفياً من `web/Admin/DashboardController`، مع تعديلين
 * واعيين:
 *
 *  1. لا `__()` على أي شيء. الويب يترجم على السيرفر (labels الرسوم، وصف
 *     الأنشطة) لأن الـBlade يُصيَّر بلغة الجلسة. واجهة React تترجم **على
 *     العميل**، فإرسال نص مترجم من السيرفر يثبّت اللغة ويمنع التبديل الفوري.
 *     لذلك نرسل بيانات خام (`role`, `created_at`) والعميل يبني الجملة.
 *  2. `topPharmacies` المحسوب في الويب **غير مستخدم** في أي قالب (بيانات
 *     ميتة — انظر تقرير الجرد). لا أنقله.
 */
class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard
     */
    public function index(): JsonResponse
    {
        $stats = Cache::remember('admin_api_dashboard_stats', 60, function () {
            $now = now();

            // استعلام واحد: إجماليات لكل دور + عدد المفعّلين
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
                $userRoles[$row->role] = (int) $row->total;
                if ($row->role === 'patient') {
                    $totalPatients = (int) $row->total;
                } else {
                    $totalOtherUsers += (int) $row->total;
                }
                if ($row->role === 'pharmacy') {
                    $activePharmacies = (int) $row->active;
                }
            }

            // إحصاءات الأدوية من pharmacy_medicines.quantity الحقيقي
            $stockTotals = PharmacyMedicine::query()
                ->select('medicine_id')
                ->selectRaw('SUM(quantity) as total_quantity')
                ->groupBy('medicine_id');

            $medicineStats = Medicine::query()
                ->leftJoinSub($stockTotals, 'stock_totals', function ($join) {
                    $join->on('stock_totals.medicine_id', '=', 'medicines.id');
                })
                ->selectRaw('COUNT(medicines.id) as total')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) > 10 THEN 1 ELSE 0 END) as available')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as low_stock')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock')
                ->first();

            $patientsThisMonth = User::where('role', 'patient')
                ->where('created_at', '>=', $now->copy()->startOfMonth())->count();
            $patientsLastMonth = User::where('role', 'patient')
                ->whereBetween('created_at', [$now->copy()->subMonth()->startOfMonth(), $now->copy()->startOfMonth()])
                ->count();
            $trendPatients = $patientsLastMonth > 0
                ? (int) round((($patientsThisMonth - $patientsLastMonth) / $patientsLastMonth) * 100)
                : ($patientsThisMonth > 0 ? 100 : 0);

            $newPharmaciesThisWeek = User::where('role', 'pharmacy')
                ->where('created_at', '>=', $now->copy()->startOfWeek())->count();
            $medicinesAddedRecently = Medicine::where('created_at', '>=', $now->copy()->subDays(30))->count();

            // سلسلة أسبوعية (8 أسابيع، الأقدم → الأحدث)
            Carbon::setLocale(app()->getLocale());
            $weekStart = app()->getLocale() === 'ar' ? Carbon::SATURDAY : Carbon::SUNDAY;

            $firstWeekStart = $now->copy()->subWeeks(7)->startOfWeek($weekStart);
            $patientsCreated = User::where('role', 'patient')
                ->where('created_at', '>=', $firstWeekStart)->pluck('created_at');
            $allMedicinesCreated = Medicine::where('created_at', '>=', $firstWeekStart)->pluck('created_at');
            $searchesCreated = SearchLog::where('created_at', '>=', $firstWeekStart)->pluck('created_at');
            $pharmaciesCreated = User::where('role', 'pharmacy')
                ->where('created_at', '>=', $firstWeekStart)->pluck('created_at');

            $countInWeek = function ($collection, $start, $end) {
                return $collection->filter(fn ($c) => $c >= $start && $c < $end)->count();
            };

            $chartSearches = $chartUsers = $chartMedicines = $chartPharmacies = $chartAll = $weekLabels = [];
            for ($i = 7; $i >= 0; $i--) {
                $start = $now->copy()->subWeeks($i)->startOfWeek($weekStart);
                $end = $now->copy()->subWeeks($i - 1)->startOfWeek($weekStart);
                $weekUsers = $countInWeek($patientsCreated, $start, $end);
                $weekMedicines = $countInWeek($allMedicinesCreated, $start, $end);
                $weekSearches = $countInWeek($searchesCreated, $start, $end);
                $weekPharmacies = $countInWeek($pharmaciesCreated, $start, $end);
                $chartUsers[] = $weekUsers;
                $chartMedicines[] = $weekMedicines;
                $chartSearches[] = $weekSearches;
                $chartPharmacies[] = $weekPharmacies;
                $chartAll[] = $weekSearches + $weekUsers + $weekMedicines + $weekPharmacies;
                $weekLabels[] = $start->translatedFormat('M j').' – '.$end->copy()->subDay()->translatedFormat('M j');
            }

            $pctChange = function (array $series) {
                $last = count($series) > 0 ? $series[count($series) - 1] : 0;
                $prev = count($series) >= 2 ? $series[count($series) - 2] : 0;
                if ($prev <= 0) {
                    return null;
                }

                return (int) round((($last - $prev) / $prev) * 100);
            };

            $toDataset = function (array $series, string $key, string $color) use ($pctChange) {
                $total = array_sum($series);

                return [
                    // لا label مترجم — العميل يعرض التسمية من i18n الخاص به.
                    'key' => $key,
                    'values' => $series,
                    'total' => $total,
                    'change' => $pctChange($series),
                    'average' => (int) round($total / 8),
                    'color' => $color,
                ];
            };

            return [
                'totalPatients' => $totalPatients,
                'totalOtherUsers' => $totalOtherUsers,
                'activePharmacies' => $activePharmacies,
                'totalMedicines' => (int) ($medicineStats->total ?? 0),
                'stockStatus' => [
                    'available' => (int) ($medicineStats->available ?? 0),
                    'low_stock' => (int) ($medicineStats->low_stock ?? 0),
                    'out_of_stock' => (int) ($medicineStats->out_of_stock ?? 0),
                ],
                'userRoles' => $userRoles,
                'trendPatients' => $trendPatients,
                'newPharmaciesThisWeek' => $newPharmaciesThisWeek,
                'medicinesAddedRecently' => $medicinesAddedRecently,
                'chartData' => [
                    'labels' => $weekLabels,
                    'datasets' => [
                        'all' => $toDataset($chartAll, 'all', '#1C72A6'),
                        'searches' => $toDataset($chartSearches, 'searches', '#3b82f6'),
                        'patients' => $toDataset($chartUsers, 'patients', '#10b981'),
                        'pharmacies' => $toDataset($chartPharmacies, 'pharmacies', '#f59e0b'),
                    ],
                ],
            ];
        });

        // آخر الأنشطة: بيانات خام، والجملة تُبنى على العميل
        $recentActivities = Cache::remember('admin_api_recent_activities', 30, function () {
            $recentPharmacies = User::select('name', 'role', 'created_at')
                ->where('role', 'pharmacy')->latest()->take(5);

            return User::select('name', 'role', 'created_at')
                ->where('role', 'patient')
                ->latest()
                ->take(5)
                ->union($recentPharmacies)
                ->get()
                ->sortByDesc('created_at')
                ->take(6)
                ->map(fn ($u) => [
                    'name' => (string) ($u->name ?? ''),
                    'role' => (string) ($u->role ?? ''),
                    'created_at' => $u->created_at ? $u->created_at->toIso8601String() : '',
                ])
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'recent_activities' => $recentActivities,
            ],
        ]);
    }
}
