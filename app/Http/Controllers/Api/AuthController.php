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
     * H-13: العمر المطلق الأقصى لسلسلة توكنات الـ refresh (بداية السلسلة، لا آخر دوران)
     */
    private const MAX_TOKEN_AGE_DAYS = 30;

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
            // حساب غير مفعّل — صيدلية مسجّلة تنتظر موافقة الإدارة، أو معطّلة يدوياً.
            // بيانات الدخول سُلّمت عند التسجيل (نمط OTP) — لا نعيد إرسالها هنا.
            return response()->json([
                'message' => 'Account is inactive',
                'code' => 'account_inactive',
            ], 403);
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

    /**
     * إنشاء حساب صيدلية جديد من تطبيق الموبايل (بانتظار موافقة الإدارة).
     *
     * الحساب يُنشأ غير مفعّل مع كلمة مرور عشوائية، وبانات الدخول (Pharmacy ID
     * + كلمة المرور) تُسلَّم فوراً في الاستجابة — نفس نمط OTP تبع المريض
     * (بدل SMS حتى تُضاف مكتبة الرسائل). الحساب يبقى بلا دخول حتى يوافق الأدمن.
     */
    public function pharmacyRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pharmacy_name' => 'required|string|max:150',
            'phone' => 'required|string|max:20|unique:users,phone',
            'region' => 'required|string|max:150',
        ], [
            'phone.unique' => 'رقم الهاتف مستخدم مسبقاً بحساب آخر.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات التسجيل غير صحيحة.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pharmacy = (new \App\Services\PharmacyRegistrationService)->createPending([
                'pharmacy_name' => $request->string('pharmacy_name')->trim()->toString(),
                'phone' => $request->phone,
                'region' => $request->string('region')->trim()->toString(),
            ]);

            // التسليم فوراً في الاستجابة (نمط OTP) — idempotent عبر delivered_at
            $credentials = (new \App\Services\PharmacyRegistrationService)->deliver($pharmacy);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['pharmacy_name' => [$e->getMessage()]],
            ], 422);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات التسجيل غير صحيحة.',
                'errors' => ['phone' => ['رقم الهاتف مستخدم مسبقاً بحساب آخر.']],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء حساب الصيدلية بنجاح. احفظ بيانات الدخول — الحساب ينتظر موافقة الإدارة.',
            'data' => [
                'pharmacy_id' => $credentials['pharmacy_id'],
                'password' => $credentials['password'],
                'pharmacy_name' => $pharmacy->pharmacy_name,
                'phone' => $request->phone,
                'is_active' => false,
                'status' => 'pending_approval',
            ],
        ], 201);
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

        // M-12: سباق إرسال مزدوج لنفس الرقم — القيد unique(phone) يرفض الخاسر
        // بـ QueryException (500)؛ نلتقطها ونعيد المحاولة مرة واحدة (الصف أصبح موجوداً → تحديث)
        try {
            OtpCode::updateOrCreate(
                ['phone' => $request->phone],
                [
                    'otp' => Hash::make($otp),
                    'expires_at' => now()->addMinutes(10),
                ]
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            OtpCode::where('phone', $request->phone)
                ->update(['otp' => Hash::make($otp), 'expires_at' => now()->addMinutes(10)]);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'otp' => $otp,
            'is_registered' => User::where('phone', $request->phone)->exists(),
        ]);
    }

    public function verifyOtp(Request $request)
    {
        // يحدد تدفق الطلب: تسجيل دخول (رقم موجود) أو تسجيل حساب جديد (رقم غير موجود)
        $userExists = User::where('phone', $request->phone)->exists();

        // المرحلة 1: صحة صيغة الهاتف وOTP — عقد OTP القديم (400) للمستخدم الموجود،
        // و422 بعلامة registration_required للرقم الجديد
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'otp' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            if ($userExists) {
                return response()->json(['message' => 'Invalid OTP format'], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'بيانات التسجيل مطلوبة لإنشاء حساب جديد',
                'errors' => $validator->errors(),
                'registration_required' => true,
            ], 422);
        }

        $limiterKey = $request->ip().'|'.$request->phone;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['message' => 'Too many attempts. Please try again later.'], 429);
        }

        $otpRecord = OtpCode::where('phone', $request->phone)
            ->where('expires_at', '>', now())
            ->first();

        if (! $otpRecord) {
            RateLimiter::hit($limiterKey, 15 * 60);

            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        $stored = (string) $otpRecord->otp;

        // سجل OTP فاسد/معدّل يدوياً — لا تقارن bcrypt بقيمة ليست hash
        if (! Hash::isHashed($stored)) {
            RateLimiter::hit($limiterKey, 15 * 60);

            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        if (! Hash::check($request->otp, $stored)) {
            RateLimiter::hit($limiterKey, 15 * 60);

            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        // المرحلة 2: بعد نجاح OTP فقط — بيانات التسجيل مطلوبة لإنشاء حساب جديد
        // الموقع إجباري (إحداثيات GPS من Flutter)
        if (! $userExists) {
            $regValidator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'birth_date' => 'required|date|before_or_equal:today',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'notifications_enabled' => 'nullable|boolean',
            ]);

            if ($regValidator->fails()) {
                // بدون حذف سجل OTP وبدون ضرب الـ rate limiter — Flutter يعيد الإرسال بنفس الكود
                return response()->json([
                    'success' => false,
                    'message' => 'بيانات التسجيل مطلوبة لإنشاء حساب جديد',
                    'errors' => $regValidator->errors(),
                    'registration_required' => true,
                ], 422);
            }
        }

        $isNew = false;

        $result = DB::transaction(function () use ($request, $otpRecord, $userExists, &$isNew) {
            $user = User::where('phone', $request->phone)->first();

            if (! $user) {
                // لا يوجد إنشاء تلقائي — الحساب يُنشأ فقط بعد استلام بيانات التسجيل الكاملة
                // M-12: سباق أول دخول متزامن لنفس الرقم — users.phone unique يرفض الخاسر
                // بـ 500؛ نلتقطها ونعيد الجلب ونكمل كدخول مستخدم موجود
                try {
                    $user = User::create([
                        'name' => $request->string('name')->trim()->toString(),
                        'email' => null,
                        'phone' => $request->phone,
                        'password' => Hash::make(Str::random(32)),
                        'birth_date' => $request->birth_date,
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude,
                        'notifications_enabled' => $request->boolean('notifications_enabled'),
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    $user = User::where('phone', $request->phone)->first();

                    if (! $user) {
                        throw $e;
                    }
                }
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
        $currentToken = $user->currentAccessToken();

        // H-13: سقف مطلق — بداية سلسلة الـ refresh (وليس created_at الخاص بالتوكن الحالي
        // الذي يتجدد مع كل دوران)؛ للصفوف القديمة قبل الـ migration نستخدم created_at.
        // chain_started_at عمود مخصص خارج casts الـ Sanctum → يُحلّل يدوياً
        $chainStartRaw = $currentToken?->chain_started_at ?? $currentToken?->created_at;
        $chainStart = $chainStartRaw ? \Illuminate\Support\Carbon::parse($chainStartRaw) : null;

        if ($currentToken && $chainStart && $chainStart->lt(now()->subDays(self::MAX_TOKEN_AGE_DAYS))) {
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الجلسة نهائياً، يرجى تسجيل الدخول من جديد',
            ], 401);
        }

        // H-13: الإنشاء أولاً ثم الحذف — لو انقطعت العملية بينهما يبقى توكن صالح
        // (بدل قفل الحساب بين حذف القديم وإنشاء الجديد)
        $token = $user->createToken('auth_token')->plainTextToken;
        $currentToken?->delete();

        return response()->json([
            'success' => true,
            'token' => $token,
        ]);
    }
}
