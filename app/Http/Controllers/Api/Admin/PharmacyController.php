<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\SearchLog;
use App\Models\User;
use App\Services\PharmacyRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * لوحة الأدمن — إدارة الصيدليات (JSON).
 *
 * منطق الكتابة منقول حرفياً من `web/Pharmacy/PharmacyController`، بما فيها
 * الترتيب الحرج: فحص كلمة المرور، والمعاملة الواحدة، وإبطال التوكنات عند
 * تغيير كلمة المرور.
 *
 * ============================================================================
 * 🔴 بيانات الاعتماد لمرة واحدة — الفرق الجوهري عن الويب
 * ============================================================================
 * الويب يسلّم `delivered_pharmacy_id` / `delivered_password` عبر **فلاش الجلسة**
 * ثم يُعيد التوجيه (redirect) — أي أنهما يظهران بعد إعادة تحميل الصفحة.
 *
 * API بلا جلسة ولا redirect. فلو أعدنا نفس النمط (session flash) لضاعا تماماً.
 * لذلك هنا تُعاد القيم في **جسم الاستجابة مباشرة**، والعميل مسؤول عن عرضها
 * فوراً (نافذة "انسخ بيانات الدخول") وعن تحذير المستخدم أن هذه آخر مرة تظهر.
 *
 * هذا ليس تبسيطاً: بدونه لا يصل الأدمن لكلمة المرور إطلاقاً، والصيدلية تُقفل —
 * وهو بالضبط العطل `initial_*` vs `delivered_*` الذي أُصلح سابقاً في الويب.
 */
class PharmacyController extends Controller
{
    /** GET /api/admin/pharmacies */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'disabled'], true)) {
            $status = 'all';
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $pharmacies = Pharmacy::query()
            ->with('user:id,name,email,is_active')
            ->withCount('pharmacyMedicines')
            ->when($q !== '', fn ($query) => $query->where('pharmacy_name', 'like', "%{$q}%"))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'disabled', fn ($query) => $query->where('is_active', false))
            ->latest()
            ->paginate($perPage);

        $totalPharmacies = Pharmacy::count();
        $activeCount = Pharmacy::where('is_active', true)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'pharmacies' => $pharmacies->items(),
                'pagination' => [
                    'current_page' => $pharmacies->currentPage(),
                    'last_page' => $pharmacies->lastPage(),
                    'per_page' => $pharmacies->perPage(),
                    'total' => $pharmacies->total(),
                ],
                'filters' => ['q' => $q, 'status' => $status],
                'stats' => [
                    'total' => $totalPharmacies,
                    'active' => $activeCount,
                    'disabled' => $totalPharmacies - $activeCount,
                    'total_items' => PharmacyMedicine::count(),
                ],
            ],
        ]);
    }

    /** GET /api/admin/pharmacies/{pharmacy} */
    public function show(Pharmacy $pharmacy): JsonResponse
    {
        $pharmacy->load('user');

        $searchesThisMonth = SearchLog::where('user_id', $pharmacy->user_id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $totalMedicines = (int) $pharmacy->pharmacyMedicines()->count();
        $availableMedicines = (int) $pharmacy->pharmacyMedicines()->where('quantity', '>', 0)->count();
        $availabilityRate = $totalMedicines > 0 ? (int) round(($availableMedicines / $totalMedicines) * 100) : 0;

        $medicines = $pharmacy->pharmacyMedicines()
            ->with('medicine:id,trade_name,active_ingredient')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => [
                'pharmacy' => $pharmacy,
                'searches_this_month' => $searchesThisMonth,
                'availability_rate' => $availabilityRate,
                'medicines' => $medicines->items(),
                'medicines_pagination' => [
                    'current_page' => $medicines->currentPage(),
                    'last_page' => $medicines->lastPage(),
                    'total' => $medicines->total(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/admin/pharmacies
     *
     * ينشئ مستخدم الصيدلية + سجل الصيدلية، ويعيد بيانات الدخول **مرة واحدة**.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:255'],
        ]);

        $pharmacyCustomId = Pharmacy::generateUniqueCustomId();

        if ($pharmacyCustomId === null) {
            return response()->json([
                'success' => false,
                'message' => 'تعذر توليد معرّف صيدلية فريد، حاول مجدداً.',
            ], 422);
        }

        $plainPassword = Str::password(12, symbols: false);

        DB::transaction(function () use ($request, $pharmacyCustomId, $plainPassword) {
            $user = User::create([
                'name' => $request->pharmacy_name,
                'email' => null,
                'password' => Hash::make($plainPassword),
            ]);
            $user->role = 'pharmacy';
            $user->is_active = true;
            $user->must_change_password = true;
            $user->save();
            $user->syncRoles(['pharmacy']);

            $pharmacy = new Pharmacy(['pharmacy_name' => $request->pharmacy_name]);
            $pharmacy->user_id = $user->id;
            $pharmacy->pharmacy_custom_id = $pharmacyCustomId;
            $pharmacy->is_active = true;
            $pharmacy->save();
        });

        Cache::forget('pharmacies_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الصيدلية بنجاح.',
            'data' => [
                // 🔴 آخر مرة تظهر — لا تخزين، لا استرجاع
                'credentials' => [
                    'pharmacy_id' => $pharmacyCustomId,
                    'password' => $plainPassword,
                ],
            ],
        ], 201);
    }

    /** PUT/PATCH /api/admin/pharmacies/{pharmacy} */
    public function update(Request $request, Pharmacy $pharmacy): JsonResponse
    {
        $pharmacy->load('user');

        $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:20'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'email' => ['required', 'string', 'email', 'max:255',
                Rule::unique('users')->ignore($pharmacy->user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $pharmacy->user->update([
            'name' => $request->pharmacy_name,
            'email' => $request->email,
        ]);

        if ($request->filled('password')) {
            $pharmacy->user->update(['password' => Hash::make($request->password)]);
            // إبطال كل توكنات API للصيدلية بعد تغيير كلمة المرور من لوحة الأدمن
            $pharmacy->user->tokens()->delete();
        }

        $pharmacy->update([
            'pharmacy_name' => $request->pharmacy_name,
            'address' => $request->address_line.', '.$request->city.($request->area ? ', '.$request->area : ''),
            'latitude' => $request->latitude ?? 0,
            'longitude' => $request->longitude ?? 0,
            'phone_number' => $request->phone_number,
        ]);

        Cache::forget('pharmacies_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات الصيدلية بنجاح.',
        ]);
    }

    /**
     * PATCH /api/admin/pharmacies/{pharmacy}/toggle-status
     *
     * عند تفعيل صيدلية جديدة لم تُسلَّم بعد، تُعاد بيانات الدخول (نمط الويب).
     */
    public function toggleStatus(Pharmacy $pharmacy): JsonResponse
    {
        $willActivate = ! $pharmacy->is_active;
        $pharmacy->is_active = $willActivate;
        $pharmacy->save();

        if ($pharmacy->user) {
            $pharmacy->user->is_active = $pharmacy->is_active;
            $pharmacy->user->save();
        }

        $credentials = null;
        if ($willActivate && $pharmacy->delivered_at === null) {
            $credentials = (new PharmacyRegistrationService)->deliver($pharmacy);
        }

        Cache::forget('pharmacies_list_cache');
        Cache::forget('users_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الصيدلية.',
            'data' => [
                'is_active' => (bool) $pharmacy->is_active,
                'credentials' => $credentials, // null إلا عند أول تفعيل
            ],
        ]);
    }

    /**
     * PATCH /api/admin/pharmacies/{pharmacy}/reset-credentials
     *
     * المسار الوحيد لاسترجاع بيانات صيدلية فُقدت — كلمة مرور جديدة لمرة واحدة.
     */
    public function resetCredentials(Pharmacy $pharmacy): JsonResponse
    {
        $pharmacy->load('user');
        $user = $pharmacy->user;

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد حساب مرتبط بهذه الصيدلية.',
            ], 422);
        }

        $plainPassword = Str::password(12, symbols: false);

        DB::transaction(function () use ($pharmacy, $user, $plainPassword) {
            $user->password = Hash::make($plainPassword);
            $user->must_change_password = true;
            $user->save();

            $pharmacy->delivered_at = null;
            $pharmacy->pending_password = Crypt::encryptString($plainPassword);
            $pharmacy->save();
        });

        // كلمة المرور تغيّرت ⇒ أي توكن قديم يجب أن يسقط
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم توليد بيانات دخول جديدة. اعرضها الآن — لن تُعرض مرة أخرى.',
            'data' => [
                'credentials' => [
                    'pharmacy_id' => $pharmacy->pharmacy_custom_id,
                    'password' => $plainPassword,
                ],
            ],
        ]);
    }

    /** DELETE /api/admin/pharmacies/{pharmacy} */
    public function destroy(Pharmacy $pharmacy): JsonResponse
    {
        $pharmacy->load('user');

        DB::transaction(function () use ($pharmacy) {
            if ($pharmacy->user) {
                $pharmacy->user->delete();
            }
            $pharmacy->delete();
        });

        Cache::forget('pharmacies_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الصيدلية بنجاح.',
        ]);
    }
}
