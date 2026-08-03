<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OtpCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    // تسجيل دخول المريض (عن طريق OTP - بدون كلمة مرور)
    public function patientLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|exists:users,phone',
            'otp' => 'required|digits:6'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'الرجاء إدخال رقم الهاتف ورمز التحقق'], 400);
        }

        // البحث عن الكود في قاعدة البيانات
        $otpRecord = OtpCode::where('phone', $request->phone)
                            ->where('otp', $request->otp)
                            ->where('expires_at', '>', now())
                            ->first();

        if (!$otpRecord) {
            return response()->json(['message' => 'رمز التحقق غير صحيح أو انتهى صلاحيته'], 400);
        }

        // جلب المستخدم
        $user = User::where('phone', $request->phone)->first();

        // التحقق من أن الحساب مفعل
        if (!$user->is_active) {
            return response()->json([
                'message' => 'حسابك موقوف حالياً. يرجى التواصل مع الدعم.'
            ], 403);
        }

        // حذف رمز التحقق بعد الاستخدام
        $otpRecord->delete();

        // تحديث وقت التحقق
        $user->phone_verified_at = now();
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token
            ]
        ]);
    }

    // تسجيل دخول الصيدلية (بالـ Pharmacy ID + كلمة المرور)
    public function pharmacyLogin(Request $request)
    {
        // ملاحظة: هذا مثال، لو كان الـ Pharmacy ID هو رقم الهاتف أو الـ id فقط عدل حسب رغبتك
        $validator = Validator::make($request->all(), [
            'phone' => 'required|exists:users,phone',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة'], 401);
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة'], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'حسابك موقوف حالياً. يرجى التواصل مع الدعم.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token
            ]
        ]);
    }

    // تسجيل الخروج
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الخروج بنجاح'
        ]);
    }

    // إرسال كود التفعيل (OTP)
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|exists:users,phone',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'رقم الهاتف غير موجود'], 400);
        }

        $otp = rand(100000, 999999);

        OtpCode::updateOrCreate(
            ['phone' => $request->phone],
            [
                'otp' => $otp,
                'expires_at' => now()->addMinutes(10)
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال كود التفعيل بنجاح',
            'otp' => $otp
        ]);
    }

    // التحقق من الكود (تستخدم في تسجيل الدخول و التسجيل)
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|exists:users,phone',
            'otp' => 'required|digits:6'
        ]);

        $otpRecord = OtpCode::where('phone', $request->phone)
                            ->where('otp', $request->otp)
                            ->where('expires_at', '>', now())
                            ->first();

        if (!$otpRecord) {
            return response()->json(['message' => 'كود التحقق غير صحيح أو انتهى'], 400);
        }

        $user = User::where('phone', $request->phone)->first();
        $user->phone_verified_at = now();
        $user->save();

        $otpRecord->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم التحقق وتسجيل الدخول بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                ],
                'token' => $token
            ]
        ]);
    }
}
