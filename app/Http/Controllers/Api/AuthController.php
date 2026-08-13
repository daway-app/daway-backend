<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{

    public function patientLogin(Request $request)
    {
        return $this->verifyOtp($request);
    }

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

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if (!$user->is_active || !$pharmacy->is_active) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        // ✅ تحقق اختياري من البريد الإلكتروني
        if (!$user->email_verified_at) {
            return response()->json(['message' => 'Email not verified'], 403);
        }

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

        $otp = (string) rand(100000, 999999);

        OtpCode::updateOrCreate(
            ['phone' => $request->phone],
            [
                'otp' => $otp,
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

        $otpRecord = OtpCode::where('phone', $request->phone)
            ->where('otp', $request->otp)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {

            $user = User::create([
                'name' => 'New User',
                'email' => null,
                'phone' => $request->phone,
                'password' => Hash::make(Str::random(32)),
                'role' => 'patient',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
        } else {
            if (!$user->is_active) {
                return response()->json(['message' => 'Account is inactive'], 403);
            }

            // ✅ تحديث وقت التحقق
            $user->update(['phone_verified_at' => now()]);
        }

        $otpRecord->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'is_new' => $user->wasRecentlyCreated,
                ],
                'token' => $token,
            ],
        ]);
    }


    public function refreshToken(Request $request)
    {
        $user = $request->user();
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
        ]);
    }
}
