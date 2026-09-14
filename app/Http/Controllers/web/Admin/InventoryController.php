<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;

class InventoryController extends Controller
{
    public function index()
    {
        // ترقيم SQL مباشر (7) — بلا تحميل الجدول كاملاً بالكاش + array_slice.
        $medicines = Medicine::withSum('pharmacyMedicines', 'quantity')
            ->withCount('pharmacyMedicines')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        // نفس العقد الذي يتوقعه الـ view (كائنات بنفس الحقول السابقة).
        $medicines->getCollection()->transform(function ($m) {
            return (object) [
                'id' => $m->id,
                'trade_name' => $m->trade_name,
                'scientific_name' => $m->active_ingredient ?? null,
                'pharmacy_medicines_sum_quantity' => $m->pharmacy_medicines_sum_quantity ?? 0,
                'pharmacy_medicines_count' => $m->pharmacy_medicines_count ?? 0,
            ];
        });

        // ملخص المخزون — استعلام تجميعي رخيص مباشر بلا كاش، وبنفس الشكل السابق.
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

        $stockSummary = (object) [
            'totalItems' => (int) ($summary->totalItems ?? 0),
            'inStock' => (int) ($summary->inStock ?? 0),
            'lowStock' => (int) ($summary->lowStock ?? 0),
            'outOfStock' => (int) ($summary->outOfStock ?? 0),
        ];

        return view('inventory.index', compact('medicines', 'stockSummary'));
    }
}
