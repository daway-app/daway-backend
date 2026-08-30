<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User; // Added this import
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Display the login form.
     *
     * @return View
     */
    public function loginForm()
    {
        return view('auth.login');
    }

    /**
     * Handle an authentication attempt.
     *
     * @return RedirectResponse
     */
    public function login(Request $request)
    {
        if (RateLimiter::tooManyAttempts('login:'.$request->ip(), 5)) {
            return back()->withErrors([
                'identity' => 'محاولات كثيرة، انتظر قليلاً.',
            ])->onlyInput(['identity', 'account_type']);
        }
        RateLimiter::hit('login:'.$request->ip(), 60);

        $credentials = $request->validate([
            'identity' => ['required', 'string'],
            'password' => ['required', 'string'],
            'account_type' => ['required', 'string'],
        ]);

        $accountType = $credentials['account_type'];
        $identity = $credentials['identity'];
        $password = $credentials['password'];
        $remember = $request->boolean('remember');

        // دعم القيمة بالإنجليزية أو العربية لنوع الحساب
        if ($accountType === 'pharmacy' || $accountType === 'صيدلية') {
            // 1. البحث عن الصيدلية باستخدام الـ Pharmacy ID (مثل PH-QGWV) في جدول pharmacies
            $pharmacy = Pharmacy::where('pharmacy_custom_id', $identity)->first();

            if (! $pharmacy) {
                return back()->withErrors([
                    'identity' => 'بيانات الاعتماد غير صحيحة.',
                ])->onlyInput(['identity', 'account_type']);
            }

            // 2. جلب المستخدم المرتبط بهذه الصيدلية عبر العلاقة user()
            $user = $pharmacy->user;

            if (! $user) {
                return back()->withErrors([
                    'identity' => 'بيانات الاعتماد غير صحيحة.',
                ])->onlyInput(['identity', 'account_type']);
            }

            // 3. التحقق من تفعيل الحساب
            if (! $pharmacy->is_active || ! $user->is_active) {
                return back()->withErrors([
                    'identity' => 'الحساب معطل.',
                ])->onlyInput(['identity', 'account_type']);
            }

            // 4. التحقق من تطابق كلمة المرور وتسجيل الدخول
            if (! Hash::check($password, $user->password)) {
                return back()->withErrors([
                    'identity' => 'بيانات الاعتماد غير صحيحة.',
                ])->onlyInput(['identity', 'account_type']); // Keep identity input for convenience
            }

            // If all checks pass
            Auth::login($user, $remember);
            $request->session()->regenerate();

            $pharmacy = $user->pharmacy;

            if ($pharmacy && ! $pharmacy->profileCompleted()) {
                return redirect()->route('pharmacy.profile.complete.show')
                    ->with('warning', __('pharmacy.profile.complete.required_message'));
            }

            // Redirect pharmacy users to their specific dashboard
            return redirect()->route('pharmacy.dashboard.index');

        } else {
            // المصادقة العادية للأدمن أو الحسابات الأخرى باستخدام البريد الإلكتروني
            $authCredentials = [
                'email' => $identity,
                'password' => $password,
                'role' => $accountType,
            ];

            if (Auth::attempt($authCredentials, $remember)) {
                $request->session()->regenerate();

                if (! Auth::user()->is_active) {
                    Auth::logout();

                    return back()->withErrors([
                        'identity' => 'الحساب معطل.',
                    ])->onlyInput(['identity', 'account_type']);
                }

                // Redirect admin/other users to the general dashboard
                return redirect()->route('dashboard');
            }
        }

        // Generic fallback error for admin/other if Auth::attempt fails
        return back()->withErrors([
            'identity' => 'بيانات الاعتماد غير صحيحة.',
        ])->onlyInput(['identity', 'account_type']);
    }

    /**
     * Log the user out of the application.
     *
     * @return RedirectResponse
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login.show');
    }
}
