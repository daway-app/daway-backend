<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * تسجيل دخول الصيدلية باستخدام Pharmacy ID وكلمة المرور.
     */
    public function pharmacyLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pharmacy_id' => 'required|string|exists:pharmacies,pharmacy_custom_id',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        $pharmacy = Pharmacy::with('user')
            ->where('pharmacy_custom_id', $request->pharmacy_id)
            ->first();
        $user = $pharmacy?->user;

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if (! $user->is_active || ! $pharmacy->is_active) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        // ملاحظة: لا يوجد فحص لتوثيق البريد الإلكتروني هنا — مطابقة لسلوك تسجيل دخول الويب،
        // والتطبيق لا يرسل إيميلات توثيق أصلاً (فحص email_verified_at كان يحظر كل الصيدليات)

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'pharmacy_id' => $pharmacy->pharmacy_custom_id,
                    'role' => $user->role,
                    'must_change_password' => (bool) $user->must_change_password,
                ],
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid phone number'], 400);
        }

        OtpCode::where('phone', $request->phone)
            ->where('expires_at', '<', now())
            ->delete();

        $otp = (string) random_int(100000, 999999);

        OtpCode::updateOrCreate(
            ['phone' => $request->phone],
            [
                'otp' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'otp' => $otp,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'otp' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid OTP format'], 400);
        }

        $limiterKey = $request->ip().'|'.$request->phone;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['message' => 'Too many attempts. Please try again later.'], 429);
        }

        $otpRecord = OtpCode::where('phone', $request->phone)
            ->where('expires_at', '>', now())
            ->first();

        if (! $otpRecord || ! Hash::check($request->otp, $otpRecord->otp)) {
            RateLimiter::hit($limiterKey, 15 * 60);

            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        $isNew = false;

        $result = DB::transaction(function () use ($request, $otpRecord, &$isNew) {
            $user = User::where('phone', $request->phone)->first();

            if (! $user) {

                $user = User::create([
                    'name' => 'New User',
                    'email' => null,
                    'phone' => $request->phone,
                    'password' => Hash::make(Str::random(32)),
                ]);
                $isNew = $user->wasRecentlyCreated;
                $user->role = 'patient';
                $user->is_active = true;
                $user->phone_verified_at = now();
                $user->save();
                $user->syncRoles(['patient']);
            } else {
                if (! $user->is_active) {
                    return response()->json(['message' => 'Account is inactive'], 403);
                }

                // ✅ تحديث وقت التحقق
                $user->phone_verified_at = now();
                $user->save();
            }

            if ($user->role !== 'patient') {
                return response()->json(['message' => 'OTP login is not allowed for this account'], 403);
            }

            $otpRecord->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            return [$user, $token];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        RateLimiter::clear($limiterKey);

        [$user, $token] = $result;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'is_new' => $isNew,
                ],
                'token' => $token,
            ],
        ]);
    }

    public function refreshToken(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
        ]);
    }
}
