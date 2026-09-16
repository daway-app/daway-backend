<?php

namespace App\Http\Controllers\web\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * تغيير كلمة المرور المؤقتة (أول دخول لصيدلية أنشأها الأدمن).
 *
 * 🔴 لماذا هذا الكنترولر موجود:
 * كان `users.must_change_password` يُرفع عند إنشاء الصيدلية/إعادة تعيين بياناتها،
 * لكن **مسار الويب لم يكن يقرأه مطلقاً** — فيبقى العلم true بلا أي أثر، وتُستخدم
 * اللوحة كاملة بكلمة مرور مؤقتة سُلّمت عبر قناة غير آمنة.
 *
 * المسار مفروض بـ middleware `password.changed` — أي صفحة أخرى تُعيد التوجيه هنا.
 */
class PasswordChangeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        // من غيّر كلمة مروره فعلاً لا يحتاج هذه الصفحة.
        if (! $user->must_change_password) {
            return redirect()->route('pharmacy.dashboard.index');
        }

        return view('auth.password-change', ['user' => $user]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.required' => __('pharmacy.password.change.current_required'),
            'password.required' => __('pharmacy.password.change.new_required'),
            'password.confirmed' => __('pharmacy.password.change.mismatch'),
            'password.min' => __('pharmacy.password.change.too_short'),
        ]);

        // التحقق من كلمة المرور الحالية يمنع استغلال جلسة مسروقة/متروكة مفتوحة.
        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => __('pharmacy.password.change.current_wrong')]);
        }

        // لا تُقبل نفس كلمة المرور المؤقتة كقيمة جديدة.
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['password' => __('pharmacy.password.change.same_as_current')]);
        }

        $user->password = Hash::make($validated['password']);
        $user->must_change_password = false;
        $user->save();

        // H-13 (مثل مسار إكمال الملف): إبطال توكنات API الصادرة بكلمة المرور القديمة.
        $user->tokens()->delete();

        return redirect()->route('pharmacy.dashboard.index')
            ->with('success', __('pharmacy.password.change.success'));
    }
}
