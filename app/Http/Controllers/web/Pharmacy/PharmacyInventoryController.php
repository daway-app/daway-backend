<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
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

        // الإحصائيات تبقى شاملة — لا تتأثر بالفلتر
        $all = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->with('medicine')->get();
        $available = $all->where('quantity', '>', 0)->count();
        $out = $all->where('quantity', '<=', 0)->count();
        $low = $all->filter(fn ($i) => $i->quantity > 0 && $i->quantity <= $threshold)->count();

        // جدول العرض يخضع للبحث والفلتر
        $items = $all;

        if ($status === 'ok') {
            $items = $items->where('quantity', '>', $threshold);
        } elseif ($status === 'low') {
            $items = $items->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
        } elseif ($status === 'out') {
            $items = $items->where('quantity', '<=', 0);
        }

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = $items->filter(function ($i) use ($needle) {
                $name = mb_strtolower((string) ($i->medicine->trade_name ?? ''));
                $ar = mb_strtolower((string) ($i->medicine->trade_name_ar ?? ''));
                $ai = mb_strtolower((string) ($i->medicine->active_ingredient ?? ''));

                return str_contains($name, $needle)
                    || ($ar !== '' && str_contains($ar, $needle))
                    || ($ai !== '' && str_contains($ai, $needle));
            })->values();
        }

        $trendLabels = [];
        $trendData = [];
        for ($i = 6; $i >= 0; $i--) {
            $trendLabels[] = now()->subDays($i)->format('d/m');
            $trendData[] = $all->where('created_at', '<=', now()->subDays($i)->toDateString())->count();
        }

        return view('pharmacy.inventory.index', compact('pharmacy', 'items', 'all', 'available', 'out', 'low', 'threshold', 'trendLabels', 'trendData', 'q', 'status'));
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
        PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->get()->each(fn ($pm) => $this->maybeNotify($pm));
        return redirect()->route('pharmacy.inventory.index')->with('success', 'تم تحديث المخزون بنجاح');
    }

    private function maybeNotify(PharmacyMedicine $pm): void
    {
        $this->notifyIfLowStock($pm);
    }

    private function notifyIfLowStock(PharmacyMedicine $pm): void
    {
        $threshold = PharmacyMedicine::LOW_STOCK_THRESHOLD;
        $pharmacyUser = $pm->pharmacy?->user;
        if (! $pharmacyUser) {
            return;
        }
        if ($pm->quantity <= 0) {
            $this->upsertLowStockNotification(
                $pharmacyUser->id,
                $pm->medicine_id,
                'out_of_stock',
                __('layout.notif_out_of_stock', ['name' => $pm->medicine?->trade_name])
            );

            return;
        }
        if ($pm->quantity > 0 && $pm->quantity <= $threshold) {
            $this->upsertLowStockNotification(
                $pharmacyUser->id,
                $pm->medicine_id,
                'low_stock',
                __('layout.notif_low_stock_pharmacy', [
                    'name' => $pm->medicine?->trade_name,
                    'count' => $pm->quantity,
                ])
            );
        }
    }

    /**
     * C7: تجنّب الإشعارات المكررة.
     */
    private function upsertLowStockNotification(int $userId, int $medicineId, string $type, string $message): void
    {
        $existing = Notification::where('user_id', $userId)
            ->where('medicine_id', $medicineId)
            ->where('type', $type)
            ->where('is_read', false)
            ->first();

        if ($existing) {
            $existing->update(['created_at' => now()]);

            return;
        }

        Notification::create([
            'user_id' => $userId,
            'medicine_id' => $medicineId,
            'type' => $type,
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
