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
            ->get();

        $totalAlternatives = $pharmacyMedicines->pluck('medicine')->sum(fn ($m) => $m->alternatives->count());
        $availableAlternatives = $pharmacyMedicines->filter(fn ($pm) => $pm->medicine->alternatives->isNotEmpty())->count();

        return view('pharmacy.alternatives.index', compact('pharmacyMedicines', 'pharmacy', 'totalAlternatives', 'availableAlternatives'));
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

        // C1: merge the two validate calls into one. Errors appear together,
        // and the alternative field is validated at the same time.
        $data = $request->validate([
            'base_medicine_id' => [
                'required',
                'exists:pharmacy_medicines,id',
                Rule::exists('pharmacy_medicines', 'id')->where(function ($query) use ($pharmacy) {
                    return $query->where('pharmacy_id', $pharmacy->id);
                }),
            ],
            'alternative_medicine_id' => [
                'required',
                'exists:medicines,id',
            ],
        ]);

        $basePharmacyMedicine = PharmacyMedicine::findOrFail($data['base_medicine_id']);
        $baseMedicine = $basePharmacyMedicine->medicine;

        // C1: self-alternative must be rejected *after* base validation to
        // avoid accidental inserts. The reverse check is also done:
        // (base→alt) must not exist while (alt→base) already does.
        if ((int) $data['alternative_medicine_id'] === (int) $baseMedicine->id) {
            return redirect()->route('pharmacy.alternatives.index')
                ->with('error', __('pharmacy.alternatives.create.self_alternative'));
        }

        // C1: detect reverse-pair before attaching. If (alt→base) exists,
        // adding (base→alt) would create a duplicate semantic edge.
        // نتحقق في pivot مباشرة: هل يوجد row (medicine_id=alt, alternative_id=base)؟
        $reverseExists = \DB::table('alternative_medicine')
            ->where('medicine_id', (int) $data['alternative_medicine_id'])
            ->where('alternative_id', (int) $baseMedicine->id)
            ->exists();

        if ($reverseExists) {
            return redirect()->route('pharmacy.alternatives.index')
                ->with('error', __('pharmacy.alternatives.create.reverse_exists'));
        }

        // C1: prevent duplicate edge
        if ($baseMedicine->alternatives()->where('alternative_id', $data['alternative_medicine_id'])->exists()) {
            return redirect()->route('pharmacy.alternatives.index')
                ->with('error', __('pharmacy.alternatives.create.already_exists'));
        }

        $baseMedicine->alternatives()->attach($data['alternative_medicine_id']);

        return redirect()->route('pharmacy.alternatives.index')
            ->with('success', __('pharmacy.alternatives.create.success'));
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

        // C1.3: detach() is atomic and returns the number of rows affected.
        // Race-condition-safe — no pre-check that can become stale.
        $deleted = $baseMedicine->alternatives()->detach($alternative->id);

        if ($deleted === 0) {
            return redirect()->route('pharmacy.alternatives.index')->with('error', __('pharmacy.alternatives.destroy.not_found'));
        }

        return redirect()->route('pharmacy.alternatives.index')->with('success', __('pharmacy.alternatives.destroy.success'));
    }
}
