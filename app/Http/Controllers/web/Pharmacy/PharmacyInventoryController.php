<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Support\LowStockNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PharmacyInventoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }
            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $threshold = PharmacyMedicine::LOW_STOCK_THRESHOLD;

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'ok', 'low', 'out'], true)) {
            $status = 'all';
        }

        // الإحصائيات تبقى شاملة — لا تتأثر بالفلتر ولا تحمّل موديلات.
        // P2: استعلام تجميعي واحد بدل 3 `count()` منفصلة على نفس الجدول
        // (نفس نمط M-24 في لوحة الصيدلية). الشروط غير متنافية
        // (`low` و`out` قد يجتمعان)، لذا كل عدّاد بـ`SUM(CASE…)` مستقل.
        $stats = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->selectRaw(
                'SUM(CASE WHEN quantity > ? THEN 1 ELSE 0 END) as available,
                 SUM(CASE WHEN quantity > 0 AND quantity <= ? THEN 1 ELSE 0 END) as low,
                 SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) as out_count',
                [$threshold, $threshold]
            )
            ->first();

        $available = (int) ($stats->available ?? 0);
        $low = (int) ($stats->low ?? 0);
        $out = (int) ($stats->out_count ?? 0);

        // جدول العرض: استعلام SQL حقيقي مع البحث والفلتر ثم ترقيم
        $rows = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->with('medicine');

        if ($status === 'ok') {
            $rows->where('quantity', '>', $threshold);
        } elseif ($status === 'low') {
            $rows->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
        } elseif ($status === 'out') {
            $rows->where('quantity', '<=', 0);
        }

        if ($q !== '') {
            $rows->whereHas('medicine', function ($mq) use ($q) {
                $mq->where('trade_name', 'like', "%{$q}%")
                    ->orWhere('active_ingredient', 'like', "%{$q}%")
                    ->orWhere('trade_name_ar', 'like', "%{$q}%");
            });
        }

        $items = $rows->orderByDesc('id')->paginate(50)->withQueryString();

        // P2: الرسم البياني كان 7 استعلامات `count()` متطابقة (واحد لكل يوم).
        // الآن استعلام واحد بـ7 تعبيرات `SUM(CASE…)` — نفس القيم بالضبط،
        // ونفس المقارنة (`created_at <= 'YYYY-MM-DD'` بلا وقت).
        $trendLabels = [];
        $cutoffs = [];
        $now = now();
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $trendLabels[] = $day->format('d/m');
            $cutoffs[] = $day->toDateString();
        }

        $trendExpr = implode(', ', array_map(
            fn (int $idx): string => "SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END) as d{$idx}",
            array_keys($cutoffs)
        ));

        $trendRow = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->selectRaw($trendExpr, $cutoffs)
            ->first();

        $trendData = [];
        foreach (array_keys($cutoffs) as $idx) {
            $trendData[] = (int) ($trendRow->{'d'.$idx} ?? 0);
        }

        return view('pharmacy.inventory.index', compact('pharmacy', 'items', 'available', 'out', 'low', 'threshold', 'trendLabels', 'trendData', 'q', 'status'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $quantities = $request->input('quantities', []);

        // P2: كان `find($id)` داخل الحلقة ⇒ استعلام SELECT لكل صف.
        // الآن جلب واحد مُجمَّع (`whereIn`) ثم معالجة من الذاكرة.
        // المجموعة الناتجة مطابقة تمامًا: نفس شرط `pharmacy_id` ونفس الصفوف.
        if ($quantities !== []) {
            $items = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                ->whereIn('id', array_keys($quantities))
                ->get()
                ->keyBy('id');

            foreach ($quantities as $id => $qty) {
                $item = $items->get((int) $id);
                if (! $item) {
                    continue;
                }
                $qty = max(0, (int) $qty);
                $item->update(['quantity' => $qty, 'is_available' => $qty > 0]);
            }
        }

        // P2: N+1 — `LowStockNotifier` يقرأ `$pm->pharmacy->user` و`$pm->medicine`
        // داخل الحلقة ⇒ 3 استعلامات لكل صف. التحميل المُسبق يُلغيها كلها.
        PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with(['pharmacy.user', 'medicine'])
            ->get()
            ->each(fn ($pm) => LowStockNotifier::notifyIfLowStock($pm));

        return redirect()->route('pharmacy.inventory.index')->with('success', 'تم تحديث المخزون بنجاح');
    }
}
