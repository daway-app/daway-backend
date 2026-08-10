<?php

namespace App\Http\Controllers\web\Pharmacy;
use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine; // Assuming this model exists for pivot table
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PharmacyMedicineController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Ensure user is authenticated
        // Add middleware to check if the user is a pharmacy
        $this->middleware(function ($request, $next) {
            if (Auth::check() && Auth::user()->role === 'pharmacy') {
                return $next($request);
            }
            return redirect('/')->with('error', 'ليس لديك صلاحية الوصول لهذه الصفحة.');
        })->except(['index', 'show']); // Apply to all methods except index and show for now
    }

    /**
     * Display a listing of the pharmacy's medicines.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Get medicines associated with this pharmacy through the pivot table
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
                                            ->with('medicine') // Eager load the Medicine details
                                            ->paginate(10);

        return view('pharmacy.medicines.index', compact('pharmacyMedicines', 'pharmacy'));
    }

    /**
     * Show the form for creating a new medicine for the pharmacy.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();
        $allMedicines = Medicine::all(); // Get all available medicines to choose from

        return view('pharmacy.medicines.create', compact('pharmacy', 'allMedicines'));
    }

    /**
     * Store a newly created medicine in storage for the pharmacy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'medicine_id' => ['required', 'exists:medicines,id',
                                Rule::unique('pharmacy_medicines')->where(function ($query) use ($pharmacy) {
                                    return $query->where('pharmacy_id', $pharmacy->id);
                                })],
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
        ], [
            'medicine_id.unique' => 'هذا الدواء موجود بالفعل في صيدليتك.',
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $request->medicine_id,
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
        ]);

        return redirect()->route('pharmacy.medicines.index')->with('success', 'تم إضافة الدواء إلى صيدليتك بنجاح.');
    }

    /**
     * Show the form for editing the specified medicine for the pharmacy.
     *
     * @param  \App\Models\PharmacyMedicine  $pharmacyMedicine
     * @return \Illuminate\Http\Response
     */
    public function edit(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', 'ليس لديك صلاحية تعديل هذا الدواء.');
        }

        return view('pharmacy.medicines.edit', compact('pharmacyMedicine', 'pharmacy'));
    }

    /**
     * Update the specified medicine in storage for the pharmacy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\PharmacyMedicine  $pharmacyMedicine
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', 'ليس لديك صلاحية تعديل هذا الدواء.');
        }

        $request->validate([
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
        ]);

        $pharmacyMedicine->update([
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
        ]);

        return redirect()->route('pharmacy.medicines.index')->with('success', 'تم تحديث الدواء بنجاح.');
    }

    /**
     * Remove the specified medicine from storage for the pharmacy.
     *
     * @param  \App\Models\PharmacyMedicine  $pharmacyMedicine
     * @return \Illuminate\Http\Response
     */
    public function destroy(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', 'ليس لديك صلاحية حذف هذا الدواء.');
        }

        $pharmacyMedicine->delete();

        return redirect()->route('pharmacy.medicines.index')->with('success', 'تم حذف الدواء من صيدليتك بنجاح.');
    }
}
