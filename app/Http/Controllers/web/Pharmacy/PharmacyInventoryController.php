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

        // الإحصائيات تبقى شاملة — عدّادات SQL مستقلة لا تتأثر بالفلتر ولا تحمّل موديلات
        $base = PharmacyMedicine::where('pharmacy_id', $pharmacy->id);
        $available = (clone $base)->where('quantity', '>', $threshold)->count();
        $low = (clone $base)->where('quantity', '>', 0)->where('quantity', '<=', $threshold)->count();
        $out = (clone $base)->where('quantity', '<=', 0)->count();

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

        $trendLabels = [];
        $trendData = [];
        for ($i = 6; $i >= 0; $i--) {
            $trendLabels[] = now()->subDays($i)->format('d/m');
            $trendData[] = (clone $base)->where('created_at', '<=', now()->subDays($i)->toDateString())->count();
        }

        return view('pharmacy.inventory.index', compact('pharmacy', 'items', 'available', 'out', 'low', 'threshold', 'trendLabels', 'trendData', 'q', 'status'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $quantities = $request->input('quantities', []);
        foreach ($quantities as $id => $qty) {
            $item = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->find($id);
            if (! $item) {
                continue;
            }
            $qty = max(0, (int) $qty);
            $item->update(['quantity' => $qty, 'is_available' => $qty > 0]);
        }
        PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->get()->each(fn ($pm) => LowStockNotifier::notifyIfLowStock($pm));
        return redirect()->route('pharmacy.inventory.index')->with('success', 'تم تحديث المخزون بنجاح');
    }
}
