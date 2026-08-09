<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'user_type' => 'required|in:pharmacy,admin',
            'login_id' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('role', $request->user_type)
            ->when($request->user_type === 'pharmacy', function ($query) use ($request) {
                return $query->where('pharmacy_id', $request->login_id);
            })
            ->when($request->user_type === 'admin', function ($query) use ($request) {
                return $query->where('email', $request->login_id);
            })
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login_id' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'login_id' => 'هذا الحساب موقوف.',
            ]);
        }

        Auth::login($user, $request->filled('remember'));
        $request->session()->regenerate();

        if ($user->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('pharmacy.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
