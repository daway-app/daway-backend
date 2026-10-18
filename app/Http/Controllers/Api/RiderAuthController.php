<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * مصادقة المندوب عبر API — تطبيق الموبايل (مندوب) الذي سيتولاه مطوّر آخر.
 *
 * ============================================================================
 * لماذا مسار منفصل وبنفس نمط `AdminAuthController`؟
 * ============================================================================
 *   · حساب المندوب لا يملك صف صيدلية، فمسار `pharmacyLogin` لا يصلح له.
 *   · نعيد استخدام نفس الآلية بالكامل (بريد/كلمة مرور + Sanctum + فحص
 *     `is_active`) — لا نظام مصادقة موازٍ ولا جدول توكنات جديد.
 *   · الدور المطلوب صريح: `rider`. حتى لو نُسخ المسار من العميل، الخادم يرفض
 *     أي دور آخر — لا تسريب صلاحية بين الأدوار.
 */
class RiderAuthController extends Controller
{
    /**
     * تسجيل دخول المندوب وإصدار توكن Sanctum.
     *
     * POST /api/login/rider
     * Body: { email: string, password: string }
     *
     * الاستجابات:
     *   200 → { success, message, data: { user: {...}, token } }
     *   401 → بيانات اعتماد غير صحيحة (موحّدة، بلا كشف وجود الحساب)
     *   403 → الحساب غير مفعّل / ليس حساب مندوب
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

        // فحص كلمة المرور أولاً — رسالة موحّدة بلا كشف وجود الحساب.
        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات الاعتماد غير صحيحة.',
            ], 401);
        }

        if ($user->role !== 'rider') {
            return response()->json([
                'success' => false,
                'message' => 'هذا الحساب ليس حساب مندوب.',
            ], 403);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'الحساب معطّل.',
                'code' => 'account_inactive',
            ], 403);
        }

        $token = $user->createToken('rider_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token,
            ],
        ]);
    }
}
