<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * التسجيل الذاتي للصيدليات (إنشاء حساب).
 *
 * الحساب يُنشأ غير مفعّل (is_active = false) وينتظر موافقة الأدمن،
 * والأدمن يفعّله من صفحة الصيدليات (pharmacies.toggleStatus).
 * بعد الموافقة تدخل الصيدلية بـ Pharmacy ID + كلمة المرور التي اختارتها.
 */
class RegisterController extends Controller
{
    /**
     * عرض نموذج إنشاء حساب الصيدلية.
     */
    public function show(): View
    {
        return view('auth.register');
    }

    /**
     * شاشة نجاح التسجيل — تقرأ معرّف الصيدلية من الـ flash فقط
     * (لا يوجد معرّف بالجلسة = زيارة مباشرة → رجوع للنموذج).
     */
    public function success(Request $request): View|RedirectResponse
    {
        $pharmacyId = $request->session()->get('registered_pharmacy_id');

        if (! $pharmacyId) {
            return redirect()->route('register.show');
        }

        return view('auth.register_success', [
            'pharmacyId' => $pharmacyId,
            'pharmacyName' => $request->session()->get('registered_pharmacy_name'),
        ]);
    }

    /**
     * إنشاء حساب صيدلية جديد (بانتظار موافقة الأدمن).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pharmacy_name' => ['required', 'string', 'max:150'],
            'phone_number' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'region' => ['required', 'string', 'max:150'],    // المنطقة / الحي
            'password' => ['required', 'string', 'min:8'],
        ], [
            'phone_number.unique' => 'رقم الهاتف مستخدم مسبقاً بحساب آخر.',
        ]);

        $pharmacyCustomId = Pharmacy::generateUniqueCustomId();

        if ($pharmacyCustomId === null) {
            return back()
                ->withErrors(['pharmacy_name' => 'تعذر توليد معرّف صيدلية فريد، حاول مجدداً.'])
                ->withInput($request->except(['password', 'password_confirmation']));
        }

        try {
            DB::transaction(function () use ($validated, $pharmacyCustomId) {
                $user = User::create([
                    'name' => $validated['pharmacy_name'],
                    'email' => null,
                    'phone' => $validated['phone_number'],
                    'password' => Hash::make($validated['password']),
                ]);

                // الحقول الحساسة خارج $fillable → تُضبط صراحةً.
                // حساب جديد = غير مفعّل حتى يوافق الأدمن (نفس ما يفعله toggleStatus).
                $user->role = 'pharmacy';
                $user->is_active = false;
                $user->must_change_password = false;
                $user->save();
                $user->syncRoles(['pharmacy']);

                $pharmacy = new Pharmacy([
                    'pharmacy_name' => $validated['pharmacy_name'],
                    // الشارع (address) يُكمله صاحب الصيدلية لاحقاً من ملفه — العمود nullable
                    'region' => $validated['region'],
                    'phone_number' => $validated['phone_number'],
                ]);
                $pharmacy->user_id = $user->id;
                $pharmacy->pharmacy_custom_id = $pharmacyCustomId;
                $pharmacy->is_active = false;
                // profile_completed_at يبقى null → أول دخول بعد الموافقة يوجّه لإكمال الملف
                $pharmacy->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            // سباق تسجيل متزامن بنفس رقم الهاتف — قيد users.phone يرفض الخاسر
            return back()
                ->withErrors(['phone_number' => 'رقم الهاتف مستخدم مسبقاً بحساب آخر.'])
                ->withInput($request->except(['password', 'password_confirmation']));
        }

        // لا نسجّل دخولاً — الحساب غير مفعّل بعد.
        // نمرّر المعرّف فقط (لا كلمة المرور — الصيدلية اختارتها بنفسها).
        return redirect()
            ->route('register.success')
            ->with('registered_pharmacy_id', $pharmacyCustomId)
            ->with('registered_pharmacy_name', $validated['pharmacy_name']);
    }
}
