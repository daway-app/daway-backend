<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redirect;

class MedicineController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $perPage = 10;
        $page = (int) request()->get('page', 1);

        $data = Cache::remember('medicines_list_cache_v3', 30, function () {
            $medicines = Medicine::with('pharmacyMedicines')->latest()->get();

            $rows = $medicines->map(function ($m) {
                $pms = $m->pharmacyMedicines;
                $totalStock = (int) $pms->sum('quantity');
                $prices = $pms->pluck('price')->filter(fn ($p) => (float) $p > 0);

                return [
                    'id' => $m->id,
                    'trade_name' => $m->trade_name,
                    'active_ingredient' => $m->active_ingredient,
                    'stock' => $totalStock,
                    'is_available' => (bool) $m->is_available,
                    'pharmacy_count' => $pms->count(),
                    'min_price' => $prices->min() !== null ? (float) $prices->min() : null,
                ];
            })->values()->all();

            return ['rows' => $rows, 'total' => count($rows)];
        });

        $rows = $data['rows'];
        $total = $data['total'];

        $out = count(array_filter($rows, fn ($r) => $r['stock'] <= 0));
        $low = count(array_filter($rows, fn ($r) => $r['stock'] > 0 && $r['stock'] <= 10));
        $available = max(0, $total - $out - $low);

        $pct = fn ($count) => $total > 0 ? round(($count / $total) * 100) : 0;

        $inPharmacy = count(array_filter($rows, fn ($r) => $r['pharmacy_count'] > 0));

        $stats = [
            'total' => $total,
            'available' => $available,
            'low' => $low,
            'out' => $out,
            'available_pct' => $pct($available),
            'low_pct' => $pct($low),
            'out_pct' => $pct($out),
            'in_pharmacy_pct' => $pct($inPharmacy),
            'not_in_pharmacy_pct' => $pct($total - $inPharmacy),
        ];

        $items = array_map(function ($row) {
            return (object) $row;
        }, array_slice($rows, ($page - 1) * $perPage, $perPage));

        $medicines = new LengthAwarePaginator($items, $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return view('medicines.index', compact('medicines', 'stats'));
    }

    private function clearMedicinesIndexCache()
    {
        Cache::forget('medicines_list_cache');
        Cache::forget('medicines_list_cache_v2');
        Cache::forget('medicines_list_cache_v3');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $allMedicines = Medicine::all();

        return view('medicines.create', compact('allMedicines'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'active_ingredient' => 'required|string|max:255',
            'description' => 'nullable|string',
            'alternatives' => 'nullable|array',
            'alternatives.*' => 'exists:medicines,id',
        ]);

        $medicine = Medicine::create([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
            'description' => $request->description,
        ]);

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        }

        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم إضافة الدواء بنجاح!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $medicine = Medicine::with(['alternatives', 'pharmacyMedicines.pharmacy'])->findOrFail($id);

        return view('medicines.show', compact('medicine'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $medicine = Medicine::with('alternatives')->findOrFail($id);
        $allMedicines = Medicine::where('id', '!=', $id)->get();

        return view('medicines.edit', compact('medicine', 'allMedicines'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $medicine = Medicine::findOrFail($id);

        $request->validate([
            'name_ar' => 'required|string|max:255',
            'active_ingredient' => 'required|string|max:255',
            'description' => 'nullable|string',
            'alternatives' => 'nullable|array',
            'alternatives.*' => 'exists:medicines,id',
        ]);

        $medicine->update([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
            'description' => $request->description,
        ]);

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        } else {
            $medicine->alternatives()->detach();
        }

        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم تحديث الدواء بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        Medicine::destroy($id);
        $this->clearMedicinesIndexCache();

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');

        return Redirect::route('medicines.index')->with('success', 'تم حذف الدواء بنجاح!');
    }
}
