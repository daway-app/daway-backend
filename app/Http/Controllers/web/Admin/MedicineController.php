<?php

namespace App\Http\Controllers\web\Admin;
use App\Http\Controllers\Controller;
use App\Models\Medicine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class MedicineController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $medicines = Medicine::latest()->paginate(10);
        return view('medicines.index', compact('medicines'));
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
            'alternatives' => 'nullable|array',
            'alternatives.*' => 'exists:medicines,id',
        ]);

        $medicine->update([
            'trade_name' => $request->name_ar,
            'active_ingredient' => $request->active_ingredient,
        ]);

        if ($request->has('alternatives')) {
            $medicine->alternatives()->sync($request->alternatives);
        } else {
            $medicine->alternatives()->detach();
        }

        return Redirect::route('medicines.index')->with('success', 'تم تحديث الدواء بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        Medicine::destroy($id);
        return Redirect::route('medicines.index')->with('success', 'تم حذف الدواء بنجاح!');
    }
}
