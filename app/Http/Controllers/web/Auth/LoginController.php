<?php

namespace App\Http\Controllers\web\Auth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Pharmacy; // Added this import
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Display the login form.
     *
     * @return \Illuminate\View\View
     */
    public function loginForm()
    {
        return view('auth.login');
    }

    /**
     * Handle an authentication attempt.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
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

            if (!$pharmacy) {
                return back()->withErrors([
                    'identity' => 'معرف الصيدلية غير موجود.',
                ])->onlyInput('identity');
            }

            // 2. جلب المستخدم المرتبط بهذه الصيدلية عبر العلاقة user()
            $user = $pharmacy->user;

            if (!$user) {
                return back()->withErrors([
                    'identity' => 'لا يوجد مستخدم مرتبط بهذا الصيدلية.',
                ])->onlyInput('identity');
            }

            // 3. التحقق من تطابق كلمة المرور وتسجيل الدخول
            if (!Hash::check($password, $user->password)) {
                return back()->withErrors([
                    'password' => 'كلمة المرور غير صحيحة.',
                ])->onlyInput('identity'); // Keep identity input for convenience
            }

            // If all checks pass
            Auth::login($user, $remember);
            $request->session()->regenerate();
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
                // Redirect admin/other users to the general dashboard
                return redirect()->route('dashboard.index');
            }
        }

        // Generic fallback error for admin/other if Auth::attempt fails
        return back()->withErrors([
            'identity' => 'بيانات الاعتماد المقدمة غير مطابقة لسجلاتنا.',
        ])->onlyInput('identity');
    }

    /**
     * Log the user out of the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login.show');
    }

    /**
     * Display the OTP verification form.
     *
     * @return \Illuminate\View\View
     */
    public function otpForm()
    {
        return view('auth.otp');
    }

    /**
     * Handle OTP verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|array|size:6',
            'otp.*' => 'required|numeric|digits:1',
        ]);

        $otpCode = implode('', $request->otp);

        if ($otpCode === '123456') {
             return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'otp' => 'The provided OTP is invalid.',
        ])->onlyInput('otp');
    }
}
