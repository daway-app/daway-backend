<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine; // Assuming this model exists for pivot table
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

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

            return redirect('/')->with('error', __('pharmacy.access_denied'));
        })->except(['index', 'show']); // Apply to all methods except index and show for now
    }

    /**
     * Display a listing of the pharmacy's medicines.
     *
     * @return Response
     */
    public function index()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Get medicines associated with this pharmacy through the pivot table
        $pharmacyMedicines = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->with('medicine') // Eager load the Medicine details
            ->paginate(10);

        $availableCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('is_available', true)
            ->where('quantity', '>', 0)
            ->count();

        $outCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where(function ($query) {
                $query->where('is_available', false)
                    ->orWhere('quantity', '<=', 0);
            })
            ->count();

        $lowCount = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('quantity', '>', 0)
            ->where('quantity', '<=', 10)
            ->count();

        return view('pharmacy.medicines.index', compact('pharmacyMedicines', 'pharmacy', 'availableCount', 'outCount', 'lowCount'));
    }

    /**
     * Show the form for creating a new medicine for the pharmacy.
     *
     * @return Response
     */
    public function create()
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $suggestedAlternatives = Medicine::alternativesByActiveIngredient(null);

        return view('pharmacy.medicines.create', compact('pharmacy', 'suggestedAlternatives'));
    }

    /**
     * بحث فوري عن دواء في الكتالوج العام وفي كتالوج وزارة الصحة.
     *
     * @return JsonResponse
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        SearchLog::track($q, 'pharmacy');

        $medicines = Medicine::where('trade_name', 'like', "%{$q}%")
            ->orWhere('active_ingredient', 'like', "%{$q}%")
            ->limit(10)
            ->get()
            ->map(fn (Medicine $m) => [
                'type' => 'medicine',
                'id' => $m->id,
                'name' => $m->trade_name,
                'sub' => $m->active_ingredient,
            ]);

        $mohMedicines = MohMedicine::where('trade_name', 'like', "%{$q}%")
            ->orWhere('generic_name', 'like', "%{$q}%")
            ->orWhere('manufacturer', 'like', "%{$q}%")
            ->limit(20)
            ->get()
            ->map(fn (MohMedicine $m) => [
                'type' => 'moh',
                'id' => $m->id,
                'name' => $m->trade_name,
                'sub' => $m->generic_name ?? $m->manufacturer,
                'official_price' => $m->official_price,
            ]);

        return response()->json([...$medicines, ...$mohMedicines]);
    }

    /**
     * Store a newly created medicine in storage for the pharmacy.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        $request->validate([
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
            'min_stock' => 'nullable|integer|min:0',
        ], [
            'price.required' => __('pharmacy.medicines.create.price_required'),
            'quantity.required' => __('pharmacy.medicines.create.quantity_required'),
        ]);

        // 1) دواء مختار من الكتالوج العام
        // 2) دواء من كتالوج وزارة الصحة (يُضاف تلقائياً للكتالوج العام عند الحاجة)
        // 3) إضافة يدوية ببيانات كاملة
        if ($request->filled('medicine_id')) {
            $medicine = Medicine::findOrFail($request->medicine_id);
        } elseif ($request->filled('moh_medicine_id')) {
            $moh = MohMedicine::findOrFail($request->moh_medicine_id);
            $medicine = Medicine::where('trade_name', $moh->trade_name)->first()
                ?? Medicine::create([
                    'trade_name' => $moh->trade_name,
                    'active_ingredient' => $moh->generic_name ?? $moh->trade_name,
                    'description' => $moh->manufacturer ?? $moh->company,
                ]);
        } else {
            $request->validate([
                'trade_name' => 'required|string|max:150',
                'active_ingredient' => 'required|string|max:150',
                'min_stock' => 'nullable|integer|min:0',
            ], [
                'trade_name.required' => __('pharmacy.medicines.create.trade_name_required'),
                'active_ingredient.required' => __('pharmacy.medicines.create.ingredient_required'),
            ]);

            $medicine = Medicine::where('trade_name', $request->trade_name)->first()
                ?? Medicine::create([
                    'trade_name' => $request->trade_name,
                    'active_ingredient' => $request->active_ingredient,
                ]);
        }

        $exists = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors([
                'medicine_id' => __('pharmacy.medicines.create.already_exists'),
            ]);
        }

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
            'min_stock' => $request->min_stock,
        ]);

        $this->notifyIfLowStock($pharmacyMedicine);

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.create.success'));
    }

    /**
     * Show the form for editing the specified medicine for the pharmacy.
     *
     * @return Response
     */
    public function edit(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.edit.not_found'));
        }

        $suggestedAlternatives = Medicine::alternativesByActiveIngredient(
            $pharmacyMedicine->medicine?->active_ingredient,
            $pharmacyMedicine->medicine_id
        );

        return view('pharmacy.medicines.edit', compact('pharmacyMedicine', 'pharmacy', 'suggestedAlternatives'));
    }

    /**
     * Update the specified medicine in storage for the pharmacy.
     *
     * @return Response
     */
    public function update(Request $request, PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.edit.not_found'));
        }

        $request->validate([
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0', // Changed from 'stock' to 'quantity'
            'is_available' => 'boolean',
            'min_stock' => 'nullable|integer|min:0',
        ]);

        $pharmacyMedicine->update([
            'price' => $request->price,
            'quantity' => $request->quantity, // Changed from 'stock' to 'quantity'
            'is_available' => $request->boolean('is_available'),
            'min_stock' => $request->min_stock,
        ]);

        $this->notifyIfLowStock($pharmacyMedicine);

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.edit.success'));
    }

    /**
     * Remove the specified medicine from storage for the pharmacy.
     *
     * @return Response
     */
    public function destroy(PharmacyMedicine $pharmacyMedicine)
    {
        $user = Auth::user();
        $pharmacy = Pharmacy::where('user_id', $user->id)->firstOrFail();

        // Ensure the pharmacy medicine belongs to the authenticated pharmacy
        if ($pharmacyMedicine->pharmacy_id !== $pharmacy->id) {
            return redirect()->route('pharmacy.medicines.index')->with('error', __('pharmacy.medicines.destroy.error'));
        }

        $pharmacyMedicine->delete();

        return redirect()->route('pharmacy.medicines.index')->with('success', __('pharmacy.medicines.destroy.success'));
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
