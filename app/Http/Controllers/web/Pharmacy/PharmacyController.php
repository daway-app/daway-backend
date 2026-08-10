<?php

namespace App\Http\Controllers\web\Pharmacy;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User; // Import the User model
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash; // Import Hash facade
use Illuminate\Validation\Rule; // Import Rule for validation

class PharmacyController extends Controller
{
    public function index()
    {
        $pharmacies = Pharmacy::withCount('pharmacyMedicines')->latest()->paginate(10);
        return view('pharmacies.index', compact('pharmacies'));
    }

    public function create()
    {
        return view('pharmacies.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'pharmacy_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'address_line' => 'required|string|max:255', // Specific address line
            'city' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'email' => 'required|string|email|max:255|unique:users', // Email for the user
            'password' => 'required|string|min:8|confirmed', // Password for the user
        ]);

        // 1. Create the User account for the pharmacy
        $user = User::create([
            'name' => $request->pharmacy_name, // Use pharmacy name as user name
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'pharmacy', // Assign 'pharmacy' role
        ]);

        // 2. Create the Pharmacy record
        $pharmacy = Pharmacy::create([
            'user_id' => $user->id,
            'pharmacy_custom_id' => 'PH-' . Str::upper(Str::random(4)),
            'pharmacy_name' => $request->pharmacy_name,
            'address' => $request->address_line . ', ' . $request->city . ($request->area ? ', ' . $request->area : ''),
            'latitude' => $request->latitude ?? 0,
            'longitude' => $request->longitude ?? 0,
            'phone_number' => $request->phone_number,
            'is_active' => true, // Default to active
        ]);

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_added_success'));
    }

    public function show(string $id)
    {
        $pharmacy = Pharmacy::with('user', 'pharmacyMedicines.medicine')->findOrFail($id);
        return view('pharmacies.show', compact('pharmacy'));
    }

    public function edit(string $id)
    {
        $pharmacy = Pharmacy::with('user')->findOrFail($id);
        // Extract city and address_line from the full address for form pre-filling
        $fullAddress = explode(', ', $pharmacy->address);
        $pharmacy->address_line = $fullAddress[0] ?? '';
        $pharmacy->city = $fullAddress[1] ?? '';
        $pharmacy->area = $fullAddress[2] ?? ''; // Assuming area is the third part

        return view('pharmacies.edit', compact('pharmacy'));
    }

    public function update(Request $request, string $id)
    {
        $pharmacy = Pharmacy::with('user')->findOrFail($id);

        $request->validate([
            'pharmacy_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'address_line' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($pharmacy->user->id)],
            'password' => 'nullable|string|min:8|confirmed', // Password is optional for update
        ]);

        // 1. Update the associated User account
        $pharmacy->user->update([
            'name' => $request->pharmacy_name,
            'email' => $request->email,
        ]);

        if ($request->filled('password')) {
            $pharmacy->user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        // 2. Update the Pharmacy record
        $pharmacy->update([
            'pharmacy_name' => $request->pharmacy_name,
            'address' => $request->address_line . ', ' . $request->city . ($request->area ? ', ' . $request->area : ''),
            'latitude' => $request->latitude ?? 0,
            'longitude' => $request->longitude ?? 0,
            'phone_number' => $request->phone_number,
        ]);

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_updated_success'));
    }

    public function destroy(string $id)
    {
        $pharmacy = Pharmacy::with('user')->findOrFail($id);

        // Delete associated user first
        if ($pharmacy->user) {
            $pharmacy->user->delete();
        }

        $pharmacy->delete();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_deleted_success'));
    }

    public function toggleStatus(string $id)
    {
        $pharmacy = Pharmacy::findOrFail($id);
        $pharmacy->is_active = !$pharmacy->is_active;
        $pharmacy->save();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_status_updated_success'));
    }
}
