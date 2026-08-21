<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\SearchLog;
use App\Models\User; // Import the User model
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon; // Import Hash facade
use Illuminate\Support\Facades\Cache; // Import Rule for validation
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PharmacyController extends Controller
{
    public function index()
    {
        $perPage = 10;
        $page = (int) request()->get('page', 1);

        $data = Cache::remember('pharmacies_list_cache', 30, function () {
            $rows = Pharmacy::withCount('pharmacyMedicines')->latest()->get()->map(function ($p) {
                return [
                    'id' => $p->id,
                    'pharmacy_name' => $p->pharmacy_name,
                    'pharmacy_custom_id' => $p->pharmacy_custom_id,
                    'address' => $p->address,
                    'phone_number' => $p->phone_number,
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                    'is_active' => $p->is_active,
                    'pharmacy_medicines_count' => $p->pharmacy_medicines_count ?? 0,
                    'created_at' => $p->created_at ? $p->created_at->format('Y-m-d H:i:s') : null,
                ];
            })->values()->all();

            return ['rows' => $rows, 'total' => count($rows)];
        });

        $items = array_map(function ($row) {
            $obj = (object) $row;
            if (! empty($obj->created_at)) {
                $obj->created_at = Carbon::parse($obj->created_at);
            }

            return $obj;
        }, array_slice($data['rows'], ($page - 1) * $perPage, $perPage));

        $pharmacies = new LengthAwarePaginator($items, $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return view('pharmacies.index', compact('pharmacies'));
    }

    private function clearPharmaciesIndexCache()
    {
        Cache::forget('pharmacies_list_cache');
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

        // إنشاء حساب المستخدم + سجل الصيدلية في معاملة واحدة (فشل أحدهما يلغي الآخر)
        DB::transaction(function () use ($request) {
            // 1. Create the User account for the pharmacy
            $user = User::create([
                'name' => $request->pharmacy_name, // Use pharmacy name as user name
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $user->role = 'pharmacy'; // Assign 'pharmacy' role
            $user->is_active = true;
            // تفعيل البريد فوراً: حساب يُنشأ من لوحة التحكم موثوق،
            // وبدونه لا يستطيع صاحبه تسجيل الدخول عبر الـ API (فحص email_verified_at)
            $user->email_verified_at = now();
            $user->save();
            $user->syncRoles(['pharmacy']);

            // 2. Create the Pharmacy record
            Pharmacy::create([
                'user_id' => $user->id,
                'pharmacy_custom_id' => 'PH-'.Str::upper(Str::random(4)),
                'pharmacy_name' => $request->pharmacy_name,
                'address' => $request->address_line.', '.$request->city.($request->area ? ', '.$request->area : ''),
                'latitude' => $request->latitude ?? 0,
                'longitude' => $request->longitude ?? 0,
                'phone_number' => $request->phone_number,
                'is_active' => true, // Default to active
            ]);
        });

        $this->clearPharmaciesIndexCache();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_added_success'));
    }

    public function show(string $id)
    {
        $pharmacy = Pharmacy::with('user', 'pharmacyMedicines.medicine')->findOrFail($id);

        // عمليات البحث التي أجراها حساب الصيدلية هذا الشهر
        $searchesThisMonth = SearchLog::where('user_id', $pharmacy->user_id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // نسبة التوفر: أدوية المخزون المتوفرة (الكمية أكبر من صفر) من إجمالي أدوية الصيدلية
        $totalMedicines = $pharmacy->pharmacyMedicines->count();
        $availableMedicines = $pharmacy->pharmacyMedicines->where('quantity', '>', 0)->count();
        $availabilityRate = $totalMedicines > 0 ? round(($availableMedicines / $totalMedicines) * 100) : 0;

        return view('pharmacies.show', compact('pharmacy', 'searchesThisMonth', 'availabilityRate'));
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
            'address' => $request->address_line.', '.$request->city.($request->area ? ', '.$request->area : ''),
            'latitude' => $request->latitude ?? 0,
            'longitude' => $request->longitude ?? 0,
            'phone_number' => $request->phone_number,
        ]);

        $this->clearPharmaciesIndexCache();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_updated_success'));
    }

    public function destroy(string $id)
    {
        $pharmacy = Pharmacy::with('user')->findOrFail($id);

        // حذف المستخدم + الصيدلية في معاملة واحدة
        DB::transaction(function () use ($pharmacy) {
            // Delete associated user first
            if ($pharmacy->user) {
                $pharmacy->user->delete();
            }

            $pharmacy->delete();
        });

        $this->clearPharmaciesIndexCache();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_deleted_success'));
    }

    public function toggleStatus(string $id)
    {
        $pharmacy = Pharmacy::findOrFail($id);
        $pharmacy->is_active = ! $pharmacy->is_active;
        $pharmacy->save();

        // ربط حالة مستخدم صاحب الصيدلية بحالة الصيدلية
        if ($pharmacy->user) {
            $pharmacy->user->is_active = $pharmacy->is_active;
            $pharmacy->user->save();
            Cache::forget('users_list_cache');
        }

        $this->clearPharmaciesIndexCache();

        return redirect()->route('pharmacies.index')->with('success', __('pharmacies.pharmacy_status_updated_success'));
    }
}
