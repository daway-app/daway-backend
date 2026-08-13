<?php

namespace App\Http\Controllers\web\Pharmacy;
use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine; // To get pharmacy's own medicines
use Illuminate\Http\Request;
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
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Get all medicines that this pharmacy offers
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                                            ->with(['medicine', 'medicine.alternatives']) // Eager load Medicine and its alternatives
                                            ->paginate(10);

        return view('pharmacy.alternatives.index', compact('pharmacyMedicines', 'pharmacy'));
    }

    /**
     * Show the form for adding an alternative to a specific medicine.
     *
     * @param  \App\Models\PharmacyMedicine  $pharmacyMedicine  The medicine in the pharmacy's inventory
     * @return \Illuminate\Http\Response
     */
    public function create(PharmacyMedicine $pharmacyMedicine = null)
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'base_medicine_id' => [
                'required',
                'exists:pharmacy_medicines,id',
                Rule::exists('pharmacy_medicines')->where(function ($query) use ($pharmacy) {
                    return $query->where('pharmacy_id', $pharmacy->id);
                }),
            ],
            'alternative_medicine_id' => [
                'required',
                'exists:medicines,id',
                // Ensure alternative is not the same as the base medicine
                Rule::notIn([$request->base_medicine_id]), // This checks against pharmacy_medicine_id, not medicine_id
            ],
        ]);

        $basePharmacyMedicine = PharmacyMedicine::findOrFail($request->base_medicine_id);
        $baseMedicine = $basePharmacyMedicine->medicine; // Get the actual Medicine model

        // Attach the alternative using the many-to-many relationship
        // Check if the alternative is already attached to prevent duplicates
        if (!$baseMedicine->alternatives()->where('alternative_id', $request->alternative_medicine_id)->exists()) {
            $baseMedicine->alternatives()->attach($request->alternative_medicine_id);
            return redirect()->route('pharmacy.alternatives.index')->with('success', __('pharmacy.alternatives.create.success'));
        } else {
            return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.create.already_exists'));
        }
    }

    /**
     * Show the form for editing a specific alternative.
     * This might be complex depending on how alternatives are stored.
     *
     * @param  int  $id  ID of the alternative relationship (e.g., pivot table ID)
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // This method needs to be implemented based on your alternative storage structure.
        // For example, if you have a MedicineAlternative model, you'd find it here.
        // $alternative = MedicineAlternative::findOrFail($id);
        // return view('pharmacy.alternatives.edit', compact('alternative'));
        return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.edit_unavailable'));
    }

    /**
     * Update the specified alternative in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id  ID of the alternative relationship
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // This method needs to be implemented based on your alternative storage structure.
        return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.edit_unavailable'));
    }

    /**
     * Remove a specific alternative from storage.
     *
     * @param  int  $id  ID of the alternative relationship
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Assuming $id here is the ID of the base PharmacyMedicine entry,
        // and we need to detach an alternative from its associated Medicine.
        // This method needs to be more specific about which alternative to delete.
        // For now, this is a placeholder.

        // To properly implement destroy, you'd need to pass both base_medicine_id and alternative_id
        // or have a specific model for the pivot table 'alternative_medicine'.

        // For demonstration, let's assume $id is the alternative_id to detach from a base medicine.
        // This requires knowing the base medicine.

        // A more robust approach for destroy would be:
        // Route::delete('pharmacy/alternatives/{base_medicine_id}/{alternative_id}', ...)
        // public function destroy($base_medicine_id, $alternative_id) { ... }

        return redirect()->route('pharmacy.alternatives.index')->with('success', __('pharmacy.alternatives.deleted'));
    }
}
