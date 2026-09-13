<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Services\PharmacyRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * التسجيل الذاتي للصيدليات (إنشاء حساب).
 *
 * الحساب يُنشأ غير مفعّل (is_active = false) مع كلمة مرور عشوائية،
 * وينتظر موافقة الأدمن. الـ Pharmacy ID + كلمة المرور لا تُعرض أبداً —
 * تُسلّم للصيدلية (عبر SMS/OTP أو أي قناة) فقط بعد موافقة الأدمن.
 */
class RegisterController extends Controller
{
    /**
     * عرض نموذج إنشاء حساب الصيدلية.
     * نمسح أي flash قديم حتى الرسالة تظهر مرة واحدة فقط.
     */
    public function show(): View
    {
        session()->forget(['register_pending_notice']);

        return view('auth.register');
    }

    /**
     * إنشاء حساب صيدلية جديد (بانتظار موافقة الأدمن).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'region' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'phone.unique' => 'رقم الهاتف مستخدم مسبقاً بحساب آخر.',
        ]);

        try {
            $pharmacy = (new PharmacyRegistrationService)->createPending([
                'pharmacy_name' => $validated['pharmacy_name'],
                'phone' => $validated['phone'],
                'region' => $validated['region'],
                'password' => $validated['password'],
            ]);
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['pharmacy_name' => $e->getMessage()])
                ->withInput($request->except(['password', 'password_confirmation']));
        } catch (UniqueConstraintViolationException $e) {
            return back()
                ->withErrors(['phone' => 'رقم الهاتف مستخدم مسبقاً بحساب آخر.'])
                ->withInput($request->except(['password', 'password_confirmation']));
        }

        // لا نسجّل دخولاً — الحساب غير مفعّل بعد.
        // لا نُظهر ID ولا كلمة مرور. نوجّه لصفحة الدخول مع رسالة انتظار.
        return redirect()
            ->route('login.show')
            ->with('register_pending_notice', true)
            ->with('registered_pharmacy_name', $validated['pharmacy_name']);
    }
}
