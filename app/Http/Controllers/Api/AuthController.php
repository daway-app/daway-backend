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
        $validator = Validator::make($request->all(), [
            'pharmacy_id' => 'required|exists:users,pharmacy_id',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة'], 401);
        }

        $user = User::where('pharmacy_id', $request->pharmacy_id)->first();

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
                    'pharmacy_id' => $user->pharmacy_id,
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

   //لتسجيل الدخول أو إنشاء حساب جديد للمريض عن طريق OTP
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|exists:users,phone',
            'otp' => 'required|digits:6'
        ]);

        // 1. البحث عن كود الـ OTP في قاعدة البيانات
        $otpRecord = OtpCode::where('phone', $request->phone)
                            ->where('otp', $request->otp)
                            ->where('expires_at', '>', now())
                            ->first();

        if (!$otpRecord) {
            return response()->json(['message' => 'كود التحقق غير صحيح أو انتهى'], 400);
        }

        // 2. البحث عن المستخدم برقم الهاتف
        $user = User::where('phone', $request->phone)->first();

        // 3. إذا المستخدم غير موجود → أنشئ حساباً جديداً
        if (!$user) {
            $user = User::create([
                'name' => 'مستخدم جديد', // اسم افتراضي، يمكن تعديله لاحقاً
                'phone' => $request->phone,
                'role' => 'patient', // كل المستخدمين الجدد عبر الموبايل هم مرضى
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
        } else {
            // إذا المستخدم موجود → فقط حدّث وقت التحقق
            $user->phone_verified_at = now();
            $user->save();
        }

        // 4. حذف كود الـ OTP بعد الاستخدام
        $otpRecord->delete();

        // 5. إنشاء توكن للمستخدم
        $token = $user->createToken('auth_token')->plainTextToken;

        // 6. إرجاع الاستجابة
        return response()->json([
            'success' => true,
            'message' => $user->wasRecentlyCreated ? 'تم إنشاء الحساب وتسجيل الدخول بنجاح' : 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'is_new' => $user->wasRecentlyCreated, // حقل إضافي ليعرف الموبايل إذا كان حساباً جديداً
                ],
                'token' => $token
            ]
        ]);
    }
}
