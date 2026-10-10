<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * مصادقة لوحة الأدمن عبر API — تُستخدم من واجهة React.
 *
 * ============================================================================
 * لماذا ملف منفصل ولماذا بريد إلكتروني (وليس pharmacy_custom_id)؟
 * ============================================================================
 * `AuthController::pharmacyLogin` يبحث عن صف في جدول `pharmacies` عبر
 * `pharmacy_custom_id`، ثم يأخذ مستخدماً منه. حساب الأدمن **لا يملك صف
 * صيدلية** في الأصل، فذلك المسار لا يمكن أن ينجح له أبداً — ولهذا لم يكن
 * للأدمن أي طريق للحصول على توكن Sanctum قبل هذا الملف.
 *
 * هذا المسار يطابق **سلوك ويب الأدمن** حرفياً (انظر
 * `web/Auth/LoginController::login`، فرع `email`):
 *   · الدخول بالبريد الإلكتروني + كلمة المرور
 *   · الدور المطلوب صريحاً: `admin`
 *   · إعادة فحص `is_active` **بعد** نجاح المصادقة (نفس ترتيب الويب)
 *
 * قرار تصميمي: لا نسمح لهذا المسار بإصدار توكن لأي دور غير `admin`.
 * حتى لو نُسخ العنوان على الفرونت، الطرف الخلفي يرفض.
 */
class AdminAuthController extends Controller
{
    /**
     * تسجيل دخول الأدمن وإصدار توكن Sanctum.
     *
     * POST /api/login/admin
     * Body: { email: string, password: string }
     *
     * الاستجابات:
     *   200 → { success, message, data: { user: {...}, token } }
     *   401 → بيانات اعتماد غير صحيحة  (موحّدة، بلا كشف وجود الحساب)
     *   403 → الحساب غير مفعّل / ليس حساب أدمن
     *   422 → أخطاء تحقق
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير صالحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = trim((string) $request->input('email'));
        $user = User::where('email', $email)->first();

        // 🔴 فحص كلمة المرور أولاً — نفس ترتيب الويب، وكسبب:
        //   فحص الدور/التفعيل قبل كلمة المرور يكشف وجود بريد في النظام لأي
        //   زائر، ويُضلّل المستخدم (يظن أن مشكلته الدور بينما هي كلمة المرور).
        //   رسالة واحدة موحّدة + نفس المسار الزمني = لا تسريب.
        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الاعتماد غير صحيحة.',
            ], 401);
        }

        // بعد إثبات ملكية كلمة المرور: رسائل صريحة ومختلفة.
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب ليس حساب مدير نظام.',
            ], 403);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'الحساب معطّل.',
                'code' => 'account_inactive',
            ], 403);
        }

        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ]);
    }
}
