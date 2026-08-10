<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;

class InventoryController extends Controller
{
    public function index()
    {
        $medicines = Medicine::withSum('pharmacyMedicines', 'quantity')
            ->withCount('pharmacyMedicines')
            ->latest()
            ->paginate(20);

        $stockTotals = PharmacyMedicine::query()
            ->select('medicine_id')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->groupBy('medicine_id');

        $stockSummary = Medicine::query()
            ->leftJoinSub($stockTotals, 'stock_totals', function ($join) {
                $join->on('stock_totals.medicine_id', '=', 'medicines.id');
            })
            ->selectRaw('COUNT(medicines.id) as totalItems')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) > 20 THEN 1 ELSE 0 END) as inStock')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) BETWEEN 1 AND 20 THEN 1 ELSE 0 END) as lowStock')
            ->selectRaw('SUM(CASE WHEN COALESCE(stock_totals.total_quantity, 0) = 0 THEN 1 ELSE 0 END) as outOfStock')
            ->first();

        return view('inventory.index', compact('medicines', 'stockSummary'));
    }
}
