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

    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $items = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->with('medicine')->get();
        $available = $items->where('quantity', '>', 0)->count();
        $out = $items->where('quantity', '<=', 0)->count();
        $low = $items->filter(fn ($i) => $i->quantity > 0 && $i->quantity <= ($i->min_stock ?? 10))->count();
        $threshold = (int) ($items->whereNotNull('min_stock')->avg('min_stock') ?? 10);

        $trendLabels = [];
        $trendData = [];
        for ($i = 6; $i >= 0; $i--) {
            $trendLabels[] = now()->subDays($i)->format('d/m');
            $trendData[] = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->whereDate('created_at', '<=', now()->subDays($i)->toDateString())->count();
        }

        return view('pharmacy.inventory.index', compact('pharmacy', 'items', 'available', 'out', 'low', 'threshold', 'trendLabels', 'trendData'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $quantities = $request->input('quantities', []);
        $minStocks = $request->input('min_stocks', []);
        foreach ($quantities as $id => $qty) {
            $item = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->find($id);
            if (! $item) {
                continue;
            }
            $qty = max(0, (int) $qty);
            $payload = ['quantity' => $qty, 'is_available' => $qty > 0];
            if (array_key_exists($id, $minStocks) && $minStocks[$id] !== null && $minStocks[$id] !== '') {
                $payload['min_stock'] = max(0, (int) $minStocks[$id]);
            }
            $item->update($payload);
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
        $threshold = $pm->min_stock !== null ? (int) $pm->min_stock : 10;
        $pharmacyUser = $pm->pharmacy?->user;
        if (! $pharmacyUser) {
            return;
        }
        if ($pm->quantity <= 0) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'out_of_stock',
                'message' => __('layout.notif_out_of_stock', ['name' => $pm->medicine?->trade_name]),
                'is_read' => false,
                'created_at' => now(),
            ]);
            return;
        }
        if ($pm->quantity > 0 && $pm->quantity <= $threshold) {
            Notification::create([
                'user_id' => $pharmacyUser->id,
                'medicine_id' => $pm->medicine_id,
                'type' => 'low_stock',
                'message' => __('layout.notif_low_stock_pharmacy', [
                    'name' => $pm->medicine?->trade_name,
                    'count' => $pm->quantity,
                ]),
                'is_read' => false,
                'created_at' => now(),
            ]);
        }
    }
}
