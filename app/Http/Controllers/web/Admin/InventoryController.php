<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class InventoryController extends Controller
{
    public function index()
    {
        $perPage = 20;
        $page = (int) request()->get('page', 1);

        $data = Cache::remember('inventory_list_cache', 30, function () {
            $rows = Medicine::withSum('pharmacyMedicines', 'quantity')
                ->withCount('pharmacyMedicines')
                ->latest()
                ->get()
                ->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'trade_name' => $m->trade_name,
                        'scientific_name' => $m->active_ingredient ?? null,
                        'pharmacy_medicines_sum_quantity' => $m->pharmacy_medicines_sum_quantity ?? 0,
                        'pharmacy_medicines_count' => $m->pharmacy_medicines_count ?? 0,
                    ];
                })
                ->values()
                ->all();

            $stockTotals = PharmacyMedicine::query()
                ->select('medicine_id')
                ->selectRaw('SUM(quantity) as total_quantity')
                ->groupBy('medicine_id');

            $summary = Medicine::query()
                ->leftJoinSub($stockTotals, 'stock_totals', function ($join) {
                    $join->on('stock_totals.medicine_id', '=', 'medicines.id');
                })
                ->selectRaw('COUNT(medicines.id) as totalItems')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) > 20 THEN 1 ELSE 0 END) as inStock')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) BETWEEN 1 AND 20 THEN 1 ELSE 0 END) as lowStock')
                ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) = 0 THEN 1 ELSE 0 END) as outOfStock')
                ->first();

            return [
                'rows' => $rows,
                'total' => count($rows),
                'stockSummary' => [
                    'totalItems' => (int) ($summary->totalItems ?? 0),
                    'inStock' => (int) ($summary->inStock ?? 0),
                    'lowStock' => (int) ($summary->lowStock ?? 0),
                    'outOfStock' => (int) ($summary->outOfStock ?? 0),
                ],
            ];
        });

        $items = array_map(function ($row) {
            return (object) $row;
        }, array_slice($data['rows'], ($page - 1) * $perPage, $perPage));

        $medicines = new LengthAwarePaginator($items, $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
        $stockSummary = (object) $data['stockSummary'];

        return view('inventory.index', compact('medicines', 'stockSummary'));
    }
}
