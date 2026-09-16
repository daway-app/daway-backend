<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User; // Added this import
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
        // ملاحظة: حدّ المعدل يُدار بالكامل عبر middleware المسار
        // (throttle:login لكل IP + throttle:login-account لكل حساب) — انظر routes/web.php.
        // كان هنا RateLimiter يدوي ثالث، فكان يتجاوز حدّاً قبل الآخر ويعيد استجابة
        // مختلفة (رسالة عربية تارة و429 تارة) — أُزيل لتوحيد السلوك.

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
            // 1. البحث عن الصيدلية باستخدام الـ Pharmacy ID (مثل PH-QGWV)
            $identity = trim($identity);
            $pharmacy = Pharmacy::where('pharmacy_custom_id', $identity)->first();
            $user = $pharmacy?->user;

            // 2. التحقق من كلمة المرور **أولاً**
            // 🔴 الترتيب مقصود: كان فحص is_active قبل فحص كلمة المرور، وهذا كان
            //   (أ) يكشف وجود الحساب لأي شخص يعرف Pharmacy ID بلا كلمة مرور،
            //   (ب) ويُضلّل الصيدلية: كلمة مرور خاطئة تُعرض كـ"الحساب غير مفعّل"
            //       فتنتظر موافقة الإدارة أياماً بينما المشكلة كلمة مرور.
            //   توحيد الرسالة + نفس المسار الزمني = لا تسريب معلومات.
            if (! $user || ! Hash::check($password, $user->password)) {
                return back()->withErrors([
                    'identity' => 'بيانات الاعتماد غير صحيحة.',
                ])->onlyInput('identity', 'account_type');
            }

            // 3. التحقق من أن الحساب ما زال حساب صيدلية.
            // 🔴 بعد إثبات ملكية كلمة المرور (لا تسريب)، وقبل فحص التفعيل.
            // بدون هذا الفحص: لو تغيّر دور الحساب إلى patient/admin من لوحة الأدمن،
            // نجح الدخول ثم ردّه EnsureRole إلى صفحة الدخول برسالة عامة
            // ⇒ "الدخول كان يعمل ثم توقف" بلا أي تفسير. الرسالة الصريحة أدقّ.
            if ($user->role !== 'pharmacy') {
                return back()->withErrors([
                    'identity' => 'هذا الحساب لم يعد حساب صيدلية. تواصل مع إدارة النظام.',
                ])->onlyInput('identity', 'account_type');
            }

            // 4. التحقق من تفعيل الحساب — بعد إثبات ملكية كلمة المرور فقط
            if (! $pharmacy->is_active || ! $user->is_active) {
                return back()->withErrors([
                    'identity' => 'الحساب غير مفعّل. إن كان قد سجّل حديثاً فسيُفعَّل بعد موافقة الإدارة.',
                ])->onlyInput('identity', 'account_type');
            }

            // If all checks pass
            Auth::login($user, $remember);
            $request->session()->regenerate();

            // 5. 🔴 إلزام تغيير كلمة المرور المؤقتة — يسبق أي redirect آخر.
            //    (يحرسه أيضاً middleware password.changed على مجموعة مسارات الصيدلية)
            if ($user->must_change_password) {
                return redirect()->route('pharmacy.password.change.show')
                    ->with('warning', __('pharmacy.password.change.required_message'));
            }

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
                    ])->onlyInput('identity', 'account_type');
                }

                // Redirect admin/other users to the general dashboard
                return redirect()->route('dashboard');
            }
        }

        // Generic fallback error for admin/other if Auth::attempt fails
        return back()->withErrors([
            'identity' => 'بيانات الاعتماد غير صحيحة.',
        ])->onlyInput('identity', 'account_type');
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
