<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * لوحة الأدمن — الملف الشخصي (JSON).
 *
 * ملاحظة معمارية: `GET /api/admin/*` كلها محميّة بـ`auth:sanctum + role:admin`
 * عبر مجموعة المسارات، فهذا الكنترولر لا يعيد التحقق من الدور.
 *
 * ---------------------------------------------------------------------------
 * 🔴 لماذا منطق تغيير كلمة المرور مُكرَّر هنا ولم يُعَد استخدام مسار الصيدلية
 * ---------------------------------------------------------------------------
 * `PharmacyProfileController@changePassword` يبدأ بـ:
 *
 *     abort_unless($user->role === 'pharmacy', 403);
 *
 * أي أنه **يرفض الأدمن عمدًا**. إزالة ذلك الشرط لتوحيد المسارين كانت ستفتح
 * تغيير كلمة مرور الصيدلية من مسار الأدمن — تغيير سلوك أمني قائم لا مبرر له.
 * فالتكرار هنا مقصود: نسختان من نفس القواعد الصلبة، كل واحدة في نطاق دورها.
 *
 * القواعد المنقولة حرفيًا من مسار الصيدلية ومساره الويب:
 *   1. **التحقق من كلمة المرور الحالية** — يمنع استغلال جلسة/توكن مسروق.
 *   2. **رفض نفس كلمة المرور المؤقتة** كقيمة جديدة.
 *   3. **إبطال كل التوكنات** بعد النجاح ⇒ يتوقّع العميل إعادة تسجيل دخول.
 */
class ProfileController extends Controller
{
    /** GET /api/admin/profile */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    // المسار الخام يُحلّ إلى رابط مطلق — نفس نمط صورة الدواء.
                    'avatar_url' => \App\Support\Image::url($user->avatar),
                    'role' => $user->role,
                ],
            ],
        ]);
    }

    /** POST /api/admin/change-password */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $user = $request->user();

        // 1) كلمة المرور الحالية يجب أن تطابق — حارس ضد جلسة مسروقة.
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور الحالية غير صحيحة.',
                'errors' => ['current_password' => ['كلمة المرور الحالية غير صحيحة.']],
            ], 422);
        }

        // 2) لا تُقبل نفس كلمة المرور كقيمة جديدة.
        if (Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'كلمة المرور الجديدة مطابقة للحالية.',
                'errors' => ['password' => ['كلمة المرور الجديدة مطابقة للحالية.']],
            ], 422);
        }

        $user->password = Hash::make($data['password']);
        $user->must_change_password = false;
        $user->save();

        // 3) إبطال كل التوكنات (بما فيها الحالي) — العميل يعيد تسجيل الدخول.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح، يرجى تسجيل الدخول مرة أخرى.',
        ]);
    }
}
