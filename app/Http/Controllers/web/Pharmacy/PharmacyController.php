<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\SearchLog;
use App\Models\User; // Import the User model
use App\Services\PharmacyRegistrationService;
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
    public function index(Request $request)
    {
        // ترقيم SQL مباشر (7) + بحث/فلترة على مستوى السيرفر عبر GET.
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        if (! in_array($status, ['all', 'active', 'disabled'])) {
            $status = 'all';
        }

        $pharmacies = Pharmacy::query()
            ->withCount('pharmacyMedicines')
            ->when($q !== '', fn ($query) => $query->where('pharmacy_name', 'like', "%{$q}%"))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'disabled', fn ($query) => $query->where('is_active', false))
            ->latest()
            ->paginate(7)
            ->withQueryString();

        // الإحصائيات والرسوم من استعلامات مستقلة على الجدول الكامل — أبداً من صفحة الـ paginator.
        $totalPharmacies = Pharmacy::count();
        $activeCount = Pharmacy::where('is_active', true)->count();
        $inactiveCount = $totalPharmacies - $activeCount;
        $totalItems = \App\Models\PharmacyMedicine::count();

        $topPharmacies = Pharmacy::query()
            ->withCount('pharmacyMedicines')
            ->orderByDesc('pharmacy_medicines_count')
            ->take(5)
            ->get(['id', 'pharmacy_name']);

        return view('pharmacies.index', compact(
            'pharmacies', 'q', 'status',
            'totalPharmacies', 'activeCount', 'inactiveCount', 'totalItems', 'topPharmacies'
        ));
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
        ]);

        // M-43: توليد معرّف الصيدلية مع إعادة المحاولة عند تصادم الـ unique (نادر: 36^4)
        // المنطق موحّد في Pharmacy::generateUniqueCustomId (يُستخدم أيضاً في التسجيل الذاتي)
        $pharmacyCustomId = Pharmacy::generateUniqueCustomId();

        if ($pharmacyCustomId === null) {
            return back()->withErrors(['pharmacy_name' => 'تعذر توليد معرّف صيدلية فريد، حاول مجدداً.'])->withInput();
        }

        // M-4: كلمة مرور عشوائية مستقلة عن المعرّف العام (لم تعد = pharmacy_custom_id)
        // ويُطلب من الصيدلية تغييرها (must_change_password)
        $plainPassword = Str::password(12, symbols: false);

        // إنشاء حساب المستخدم + سجل الصيدلية في معاملة واحدة (فشل أحدهما يلغي الآخر)
        DB::transaction(function () use ($request, $pharmacyCustomId, $plainPassword) {
            // 1. Create the User account for the pharmacy
            $user = User::create([
                'name' => $request->pharmacy_name, // Use pharmacy name as user name
                'email' => null,
                'password' => Hash::make($plainPassword),
            ]);
            $user->role = 'pharmacy'; // Assign 'pharmacy' role
            $user->is_active = true;
            $user->must_change_password = true;
            $user->save();
            $user->syncRoles(['pharmacy']);

            // 2. Create the Pharmacy record (only name is set; rest filled by pharmacy on first login)
            // C1: الحقول الحساسة (user_id, pharmacy_custom_id, is_active) تُضبط صراحة بعد الإنشاء.
            $pharmacy = new Pharmacy([
                'pharmacy_name' => $request->pharmacy_name,
            ]);
            $pharmacy->user_id = $user->id;
            $pharmacy->pharmacy_custom_id = $pharmacyCustomId;
            $pharmacy->is_active = true;
            $pharmacy->save();
        });

        $this->clearPharmaciesIndexCache();

        // M-4: بيانات الدخول لمرة واحدة — تظهر للأدمن لإيصالها للصيدلية (لا تُعرض ثانية)
        return redirect()->route('pharmacies.index')
            ->with('success', __('pharmacies.pharmacy_added_success'))
            ->with('initial_pharmacy_id', $pharmacyCustomId)
            ->with('initial_password', $plainPassword);
    }

    public function show(string $id)
    {
        $pharmacy = Pharmacy::with('user')->findOrFail($id);

        // عمليات البحث التي أجراها حساب الصيدلية هذا الشهر
        $searchesThisMonth = SearchLog::where('user_id', $pharmacy->user_id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        // نسبة التوفر: أدوية المخزون المتوفرة (الكمية أكبر من صفر) من إجمالي أدوية الصيدلية.
        // إحصاء على مستوى SQL بدل تحميل كل صفوف المخزون في الذاكرة.
        $totalMedicines = (int) $pharmacy->pharmacyMedicines()->count();
        $availableMedicines = (int) $pharmacy->pharmacyMedicines()->where('quantity', '>', 0)->count();
        $availabilityRate = $totalMedicines > 0 ? round(($availableMedicines / $totalMedicines) * 100) : 0;

        // جدول المخزون: الصفحة الحالية فقط — كان @forelse يعرض كل الصفوف بلا حد
        $pharmacyMedicines = $pharmacy->pharmacyMedicines()
            ->with('medicine')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('pharmacies.show', compact('pharmacy', 'searchesThisMonth', 'availabilityRate', 'pharmacyMedicines'));
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

            // H-13: إبطال توكنات API للصيدلية بعد تغيير كلمة المرور من لوحة الأدمن
            $pharmacy->user->tokens()->delete();
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
        $willActivate = ! $pharmacy->is_active;
        $pharmacy->is_active = $willActivate;
        $pharmacy->save();

        // ربط حالة مستخدم صاحب الصيدلية بحالة الصيدلية
        if ($pharmacy->user) {
            $pharmacy->user->is_active = $pharmacy->is_active;
            $pharmacy->user->save();
            Cache::forget('users_list_cache');
        }

        // إذا كانت الصيدلية مسجّلة حديثاً (لم تُسلّم بعد) ونُشّطت الآن → نسلّم بيانات الدخول
        $credentials = null;
        if ($willActivate && $pharmacy->delivered_at === null) {
            $credentials = (new PharmacyRegistrationService)->deliver($pharmacy);
        }

        $this->clearPharmaciesIndexCache();

        $redirect = redirect()->route('pharmacies.index')
            ->with('success', __('pharmacies.pharmacy_status_updated_success'));

        if ($credentials) {
            $redirect->with('delivered_pharmacy_id', $credentials['pharmacy_id'])
                ->with('delivered_password', $credentials['password']);
        }

        return $redirect;
    }
}
