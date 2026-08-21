<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine; // To get pharmacy's own medicines
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PharmacyAlternativeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Ensure user is authenticated
        // Add middleware to check if the user is a pharmacy
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        });
    }

    /**
     * Display a listing of the pharmacy's medicines with their alternatives.
     *
     * @return Response
     */
    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Get all medicines that this pharmacy offers
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with(['medicine', 'medicine.alternatives']) // Eager load Medicine and its alternatives
            ->paginate(10);

        $allMedicines = Medicine::all(['id', 'trade_name', 'active_ingredient']);
        $totalAlternatives = $pharmacyMedicines->pluck('medicine')->sum(fn ($m) => $m->alternatives->count());
        $availableAlternatives = $pharmacyMedicines->filter(fn ($pm) => $pm->medicine->alternatives->isNotEmpty())->count();

        return view('pharmacy.alternatives.index', compact('pharmacyMedicines', 'pharmacy', 'allMedicines', 'totalAlternatives', 'availableAlternatives'));
    }

    /**
     * Show the form for adding an alternative to a specific medicine.
     *
     * @param  PharmacyMedicine  $pharmacyMedicine  The medicine in the pharmacy's inventory
     * @return Response
     */
    public function create(?PharmacyMedicine $pharmacyMedicine = null)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Get all medicines in the pharmacy's inventory
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine')
            ->get();

        // Get all general medicines (potential alternatives)
        $allMedicines = Medicine::all();

        return view('pharmacy.alternatives.create', compact('pharmacy', 'pharmacyMedicines', 'allMedicines', 'pharmacyMedicine'));
    }

    /**
     * Store a newly created alternative in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // التحقق من الدواء الأساسي أولاً (يجب أن يكون من مخزون هذه الصيدلية)
        $request->validate([
            'base_medicine_id' => [
                'required',
                'exists:pharmacy_medicines,id',
                Rule::exists('pharmacy_medicines')->where(function ($query) use ($pharmacy) {
                    return $query->where('pharmacy_id', $pharmacy->id);
                }),
            ],
        ]);

        $basePharmacyMedicine = PharmacyMedicine::findOrFail($request->base_medicine_id);
        $baseMedicine = $basePharmacyMedicine->medicine; // Get the actual Medicine model

        // التحقق من البديل: يجب أن يكون دواءً فعلياً وليس الدواء الأساسي نفسه
        // (المقارنة تتم مع medicines.id وليس pharmacy_medicines.id لأن المسافتين مختلفتان)
        $request->validate([
            'alternative_medicine_id' => [
                'required',
                'exists:medicines,id',
                Rule::notIn([$baseMedicine->id]),
            ],
        ]);

        // Attach the alternative using the many-to-many relationship
        // Check if the alternative is already attached to prevent duplicates
        if (! $baseMedicine->alternatives()->where('alternative_id', $request->alternative_medicine_id)->exists()) {
            $baseMedicine->alternatives()->attach($request->alternative_medicine_id);

            return redirect()->route('pharmacy.alternatives.index')->with('success', __('pharmacy.alternatives.create.success'));
        } else {
            return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.create.already_exists'));
        }
    }

    /**
     * Remove a specific alternative from a pharmacy's medicine.
     *
     * @param  PharmacyMedicine  $pharmacyMedicine  The medicine in the pharmacy's inventory
     * @param  Medicine  $alternative  The alternative medicine to detach
     * @return Response
     */
    public function destroy(PharmacyMedicine $pharmacyMedicine, Medicine $alternative)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy owns this inventory item
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.index.no_access'));
        }

        $baseMedicine = $pharmacyMedicine->medicine;

        // Ensure the alternative link actually exists before detaching
        if (! $baseMedicine->alternatives()->where('alternative_id', $alternative->id)->exists()) {
            return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.destroy.not_found'));
        }

        $baseMedicine->alternatives()->detach($alternative->id);

        return redirect()->route('pharmacy.alternatives.index')->with('success', __('pharmacy.alternatives.destroy.success'));
    }
}
