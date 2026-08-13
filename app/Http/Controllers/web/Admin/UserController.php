<?php

namespace App\Http\Controllers\web\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $perPage = 10;
        $page = (int) request()->get('page', 1);
        $role = request()->get('role', 'all');
        $q = mb_strtolower(trim((string) request()->get('q', '')));

        if (! in_array($role, ['all', 'admin', 'pharmacy', 'patient'])) {
            $role = 'all';
        }

        $data = Cache::remember('users_list_cache', 30, function () {
            $rows = User::latest()->get()->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'role' => $u->role,
                    'is_active' => $u->is_active,
                    'updated_at' => $u->updated_at ? $u->updated_at->format('Y-m-d H:i:s') : null,
                ];
            })->values()->all();
            return ['rows' => $rows, 'total' => count($rows)];
        });

        $rows = $data['rows'];

        if ($role !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['role'] === $role));
        }

        if ($q !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($q) {
                $hay = mb_strtolower($r['name'] . ' ' . $r['phone'] . ' ' . ($r['email'] ?? ''));
                return str_contains($hay, $q);
            }));
        }

        $users = $this->paginateArray($rows, count($rows), $perPage, $page);

        $roleCounts = ['admin' => 0, 'pharmacy' => 0, 'patient' => 0];
        foreach ($data['rows'] as $row) {
            if (isset($roleCounts[$row['role']])) {
                $roleCounts[$row['role']]++;
            }
        }

        return view('users.index', compact('users', 'roleCounts', 'role', 'q'));
    }

    private function paginateArray(array $rows, int $total, int $perPage, int $page)
    {
        $items = array_map(function ($row) {
            $obj = (object) $row;
            if (!empty($obj->updated_at)) {
                $obj->updated_at = \Illuminate\Support\Carbon::parse($obj->updated_at);
            }
            return $obj;
        }, array_slice($rows, ($page - 1) * $perPage, $perPage));

        return new \Illuminate\Pagination\LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
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

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

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
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'required|string|max:255|unique:users,phone,' . $user->id,
            'role' => 'required|string|in:admin,pharmacy,patient',
            'status' => 'required|boolean',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role,
            'is_active' => $request->status,
        ]);

        $this->clearUsersIndexCache();
        return Redirect::route('users.index')->with('success', 'تم تحديث بيانات المستخدم بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        User::destroy($id);
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
