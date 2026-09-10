<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * A1-7: ترقيم على مستوى SQL بدل تحميل الجدول كاملاً بالـ PHP
     * (كان: Cache::remember لكل الجدول + array_filter + array_slice)
     */
    public function index()
    {
        $perPage = 10;
        $role = request()->get('role', 'all');
        $q = mb_strtolower(trim((string) request()->get('q', '')));

        if (! in_array($role, ['all', 'admin', 'pharmacy', 'patient'])) {
            $role = 'all';
        }

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

        $users = $query->paginate($perPage)->withQueryString();

        $counts = User::query()
            ->selectRaw('role, COUNT(*) as c')
            ->groupBy('role')
            ->pluck('c', 'role');

        $roleCounts = [
            'admin' => (int) ($counts['admin'] ?? 0),
            'pharmacy' => (int) ($counts['pharmacy'] ?? 0),
            'patient' => (int) ($counts['patient'] ?? 0),
        ];

        return view('users.index', compact('users', 'roleCounts', 'role', 'q'));
    }

    private function clearUsersIndexCache()
    {
        Cache::forget('users_list_cache');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('users.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:255|unique:users',
            'email' => 'nullable|email|max:255|unique:users',
            'role' => 'required|string|in:admin,pharmacy,patient',
            'password' => 'required|string|min:8',
        ]);

        $user = new User;
        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->password = Hash::make($request->password);
        $user->role = $request->role;
        $user->save();
        $user->syncRoles([$user->role]);

        $this->clearUsersIndexCache();

        return Redirect::route('users.index')->with('success', 'تم إضافة المستخدم بنجاح!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        // Assuming you have a users.show view, otherwise it will throw an error.
        // Based on the previous file listing, there was no users.show.blade.php.
        // If you need one, please create it. For now, this method will not be directly used by the existing views.
        return view('users.edit', compact('user')); // Redirecting to edit for now as show view is not present.
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $user = User::findOrFail($id);

        return view('users.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'required|string|max:255|unique:users,phone,'.$user->id,
            'role' => 'required|string|in:admin,pharmacy,patient',
            'status' => 'required|boolean',
        ]);

        // منع المستخدم من تغيير دوره هو بنفسه
        if ((int) $user->id === (int) auth()->id() && $user->role !== $request->role) {
            return back()->withErrors([
                'role' => 'لا يمكنك تغيير دورك لنفسك!',
            ])->withInput();
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->role = $request->role;
        $user->is_active = (bool) $request->status;
        $user->save();
        $user->syncRoles([$user->role]);

        $this->clearUsersIndexCache();

        return Redirect::route('users.index')->with('success', 'تم تحديث بيانات المستخدم بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);

        // منع الأدمن من حذف حساب نفسه
        if ((int) $user->id === (int) auth()->id()) {
            return redirect()->back()->with('error', 'لا يمكنك حذف حسابك الخاص!');
        }

        $user->delete();
        $this->clearUsersIndexCache();

        return Redirect::route('users.index')->with('success', 'تم حذف المستخدم بنجاح!');
    }

    /**
     * تبديل حالة تفعيل/تعطيل المستخدم (زر النشط/تعطيل في قائمة المستخدمين).
     */
    public function toggleStatus(string $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === (int) auth()->id() && $user->is_active) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الحالي!',
            ], 422);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // ربط حالة الصيدلية بحالة المستخدم صاحب الصيدلية
        $pharmacy = $user->pharmacy()->first();
        if ($pharmacy) {
            $pharmacy->is_active = $user->is_active;
            $pharmacy->save();
            Cache::forget('pharmacies_list_cache');
        }

        $this->clearUsersIndexCache();

        return response()->json([
            'is_active' => (bool) $user->is_active,
            'message' => $user->is_active ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم',
        ]);
    }
}
