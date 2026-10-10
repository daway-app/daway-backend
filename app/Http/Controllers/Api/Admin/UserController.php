<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * لوحة الأدمن — المستخدمون (JSON).
 *
 * الحمايات منقولة حرفياً من `web/Admin/UserController`:
 *   · منع الأدمن من تغيير دوره بنفسه
 *   · منع تغيير دور حساب مرتبط بصيدلية (يكسر الدخول بلا تفسير)
 *   · منع الأدمن من حذف/تعطيل نفسه
 *   · مزامنة `pharmacies.is_active` مع `users.is_active`
 *
 * هذه ليست تحسينات — كل واحدة تحرس عطلاً مؤكَّداً في الويب. نقلها إلزامي.
 */
class UserController extends Controller
{
    /** GET /api/admin/users */
    public function index(Request $request): JsonResponse
    {
        $role = (string) $request->query('role', 'all');
        if (! in_array($role, ['all', 'admin', 'pharmacy', 'patient'], true)) {
            $role = 'all';
        }
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $perPage = min(100, max(1, (int) $request->query('per_page', 10)));

        $query = User::query()->latest();

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        $users = $query->paginate($perPage);

        $counts = User::query()->selectRaw('role, COUNT(*) as c')->groupBy('role')->pluck('c', 'role');

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users->items(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
                'filters' => ['q' => $q, 'role' => $role],
                'role_counts' => [
                    'admin' => (int) ($counts['admin'] ?? 0),
                    'pharmacy' => (int) ($counts['pharmacy'] ?? 0),
                    'patient' => (int) ($counts['patient'] ?? 0),
                ],
            ],
        ]);
    }

    /** GET /api/admin/users/{user} */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['user' => $user],
        ]);
    }

    /** POST /api/admin/users */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255', 'unique:users'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users'],
            'role' => ['required', 'string', 'in:admin,pharmacy,patient'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = new User;
        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->password = Hash::make($request->password);
        $user->role = $request->role;
        $user->save();
        $user->syncRoles([$user->role]);

        Cache::forget('users_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة المستخدم بنجاح.',
            'data' => ['id' => $user->id],
        ], 201);
    }

    /** PUT/PATCH /api/admin/users/{user} */
    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['required', 'string', 'max:255', 'unique:users,phone,'.$user->id],
            'role' => ['required', 'string', 'in:admin,pharmacy,patient'],
            'status' => ['required', 'boolean'],
        ]);

        // منع المستخدم من تغيير دوره هو بنفسه
        if ((int) $user->id === (int) auth()->id() && $user->role !== $request->role) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تغيير دورك لنفسك.',
                'errors' => ['role' => ['لا يمكنك تغيير دورك لنفسك.']],
            ], 422);
        }

        // منع تغيير دور حساب مرتبط بصيدلية
        if ($user->pharmacy()->exists() && $request->role !== 'pharmacy') {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب مرتبط بصيدلية — لا يمكن تغيير دوره.',
                'errors' => ['role' => ['هذا الحساب مرتبط بصيدلية — لا يمكن تغيير دوره. عطّل الحساب بدلاً من ذلك.']],
            ], 422);
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->role = $request->role;
        $user->is_active = (bool) $request->status;
        $user->save();
        $user->syncRoles([$user->role]);

        // مزامنة حالة الصيدلية
        $pharmacy = $user->pharmacy()->first();
        if ($pharmacy && (bool) $pharmacy->is_active !== (bool) $user->is_active) {
            $pharmacy->is_active = $user->is_active;
            $pharmacy->save();
            Cache::forget('pharmacies_list_cache');
        }

        Cache::forget('users_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات المستخدم بنجاح.',
        ]);
    }

    /** PATCH /api/admin/users/{user}/toggle-status */
    public function toggleStatus(User $user): JsonResponse
    {
        if ($user->id === (int) auth()->id() && $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تعطيل حسابك الحالي.',
            ], 422);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        $pharmacy = $user->pharmacy()->first();
        if ($pharmacy) {
            $pharmacy->is_active = $user->is_active;
            $pharmacy->save();
            Cache::forget('pharmacies_list_cache');
        }

        Cache::forget('users_list_cache');

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم',
            'data' => ['is_active' => (bool) $user->is_active],
        ]);
    }

    /** DELETE /api/admin/users/{user} */
    public function destroy(User $user): JsonResponse
    {
        if ((int) $user->id === (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك حذف حسابك الخاص.',
            ], 422);
        }

        $user->delete();
        Cache::forget('users_list_cache');

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المستخدم بنجاح.',
        ]);
    }
}
