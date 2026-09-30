<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Contracts\SmsProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
     * صاحب الصيدلية يختار كلمة مروره بنفسه. الحساب يُنشأ غير مفعّل، ولا يُعاد
     * أي معرّف في الاستجابة — بعد موافقة الأدمن تُرسل بيانات الدخول (Pharmacy ID
     * + كلمة المرور) للصيدلية عبر رسالة (SMS لاحقاً).
     */
    public function pharmacyRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pharmacy_name' => 'required|string|max:150',
            'phone' => 'required|string|max:20|unique:users,phone',
            'region' => 'required|string|max:150',
            'password' => 'required|string|min:8',
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
                'password' => $request->password,
            ]);
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
            'message' => 'تم إنشاء حساب الصيدلية بنجاح. بعد موافقة الإدارة ستصلك بيانات الدخول (Pharmacy ID وكلمة المرور) عبر رسالة.',
            'data' => [
                'pharmacy_name' => $pharmacy->pharmacy_name,
                'phone' => $request->phone,
                'is_active' => false,
                'status' => 'pending_approval',
            ],
        ], 201);
    }

    /**
     * إنشاء حساب مريض — الخطوة 1 من تدفق (تسجيل ثم OTP).
     *
     * يستقبل بيانات التسجيل الأساسية فقط (phone/name/age/birth_date/terms)،
     * يخزّنها مؤقتاً في الـ Cache لمدة 10 دقائق، ثم يرسل OTP بنفس سلوك
     * POST /api/otp/send (ويرجع الـ OTP في JSON مؤقتاً مثل الحالي).
     * الخطوة 2: POST /api/otp/verify بـ phone+otp فقط ينشئ الحساب.
     * الموقع والإشعارات اختيارية لاحقاً عبر POST /api/profile/patient.
     */
    public function patientRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'digits:10', 'regex:/^05[6-9]/', 'unique:users,phone'],
            'name' => 'required|string|max:255',
            'age' => 'required|integer|min:1|max:120',
            'birth_date' => 'nullable|date|before_or_equal:today',
            'terms_accepted' => 'required|boolean|accepted',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.digits' => 'رقم الهاتف يجب أن يكون 10 خانات',
            'phone.regex' => 'رقم الهاتف يجب أن يبدأ بـ 05',
            'phone.unique' => 'رقم الهاتف مسجّل مسبقاً، سجّل الدخول بدلاً من إنشاء حساب جديد.',
            'name.required' => 'الاسم مطلوب',
            'age.required' => 'العمر مطلوب',
            'age.integer' => 'العمر يجب أن يكون رقمًا صحيحًا',
            'age.min' => 'العمر غير صالح',
            'age.max' => 'العمر غير صالح',
            'terms_accepted.required' => 'يجب قبول الشروط والأحكام',
            'terms_accepted.accepted' => 'يجب قبول الشروط والأحكام',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات التسجيل مطلوبة لإنشاء حساب جديد',
                'errors' => $validator->errors(),
                'registration_required' => true,
            ], 422);
        }

        $phone = $request->string('phone')->toString();

        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'رقم الهاتف مسجّل مسبقاً، سجّل الدخول بدلاً من إنشاء حساب جديد.',
                'errors' => ['phone' => ['رقم الهاتف مسجّل مسبقاً، سجّل الدخول بدلاً من إنشاء حساب جديد.']],
                'is_registered' => true,
            ], 422);
        }

        Cache::put(
            'patient_reg:'.$phone,
            [
                'name' => $request->string('name')->trim()->toString(),
                'age' => (int) $request->input('age'),
                'birth_date' => $request->input('birth_date'),
            ],
            now()->addMinutes(10)
        );

        OtpCode::where('phone', $phone)
            ->where('expires_at', '<', now())
            ->delete();

        $otp = (string) random_int(100000, 999999);

        try {
            OtpCode::updateOrCreate(
                ['phone' => $phone],
                [
                    'otp' => Hash::make($otp),
                    'expires_at' => now()->addMinutes(10),
                ]
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            OtpCode::where('phone', $phone)
                ->update(['otp' => Hash::make($otp), 'expires_at' => now()->addMinutes(10)]);
        }

        $this->dispatchOtpSms($phone, $otp);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'otp' => $otp,
            'is_registered' => false,
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
            'phone' => ['required', 'string', 'max:20', 'digits:10'],
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

        // M-12: محاولة إرسال OTP عبر SMS — الفشل غير مسموح به لا يوقف العملية.
        // لا يتم تسجيل الـ OTP بشكل نصّي أبداً. الفشل يُسجّل كـ warning مع رقم هاتف مُخفّف.
        $this->dispatchOtpSms($request->phone, $otp);

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
            'phone' => ['required', 'string', 'max:20', 'digits:10'],
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
        // الموقع اختياري (قد يرفض المستخدم منحه، أو قد لا يكون GPS متاحاً)
        // العمر إجباري — إما كعمر عدد صحيح أو كتاريخ ميلاد
        // تدفق (تسجيل ثم OTP): بيانات POST /api/register/patient مخزنة بالـ Cache —
        // ندمجها مع الطلب حتى يكفي إرسال phone+otp فقط في verify (توافق خلفي: إن لم
        // توجد بيانات مخزنة تُستخدم البيانات المرسلة بالطلب مثل السلوك السابق).
        if (! $userExists) {
            $regInput = $request->all();
            $pending = Cache::get('patient_reg:'.$request->phone);
            if (is_array($pending)) {
                foreach (['name', 'age', 'birth_date'] as $key) {
                    if (array_key_exists($key, $pending) && $pending[$key] !== null && $pending[$key] !== '') {
                        $regInput[$key] = $pending[$key];
                    }
                }
                $regInput['terms_accepted'] = true;
            }
            $regValidator = Validator::make($regInput, [
                'name' => 'required|string|max:255',
                'age' => 'required|integer|min:1|max:120',
                'birth_date' => 'nullable|date|before_or_equal:today',
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'notifications_enabled' => 'nullable|boolean',
                'terms_accepted' => 'required|boolean|accepted',
            ], [
                'age.required' => 'العمر مطلوب',
                'age.integer' => 'العمر يجب أن يكون رقمًا صحيحًا',
                'age.min' => 'العمر غير صالح',
                'age.max' => 'العمر غير صالح',
                'terms_accepted.required' => 'يجب قبول الشروط والأحكام',
                'terms_accepted.accepted' => 'يجب قبول الشروط والأحكام',
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
                    // مصدر بيانات التسجيل: الـ Cache من POST /api/register/patient أولاً،
                    // ثم البيانات المرسلة بالطلب (توافق خلفي مع التدفق القديم).
                    $pending = Cache::get('patient_reg:'.$request->phone);
                    $regName = is_array($pending) && ! empty($pending['name'])
                        ? $pending['name']
                        : $request->string('name')->trim()->toString();
                    $regAge = is_array($pending) && array_key_exists('age', $pending)
                        ? $pending['age']
                        : $request->input('age');
                    $birthDate = $request->input('birth_date');
                    if (is_array($pending) && ! empty($pending['birth_date'])) {
                        $birthDate = $pending['birth_date'];
                    }
                    // إذا أرسل العمر كرقم، احوّله إلى birth_date
                    if (! $birthDate && $regAge !== null && $regAge !== '') {
                        $birthDate = now()->subYears((int) $regAge)->toDateString();
                    }

                    $user = User::create([
                        'name' => trim((string) $regName),
                        'email' => null,
                        'phone' => $request->phone,
                        'password' => Hash::make(Str::random(32)),
                        'birth_date' => $birthDate,
                        'latitude' => $request->input('latitude'),
                        'longitude' => $request->input('longitude'),
                        'notifications_enabled' => $request->boolean('notifications_enabled'),
                        'terms_accepted' => true,
                        'terms_accepted_at' => now(),
                    ]);
                    Cache::forget('patient_reg:'.$request->phone);
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
                    'age' => $user->birth_date ? $user->birth_date->age : null,
                    'terms_accepted' => (bool) $user->terms_accepted,
                    'notifications_enabled' => (bool) $user->notifications_enabled,
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

    /**
     * M-12: إرسال OTP عبر SMS باستخدام SMSGate.
     *
     * - الفشل غير مسموح به لا يوقف العملية (fire-and-forget)
     * - لا يتم تسجيل الـ OTP بشكل نصّي أبداً
     * - يُستخدم SmsGateService إذا كان مهيأ (SMSGATE_USERNAME/PASSWORD غير فارغة)
     * - في الوضع التطويري (بدون إعداد SMS) يُتخطّى الإرسال تماماً
     */
    private function dispatchOtpSms(string $phone, string $otp): void
    {
        try {
            $sms = app(SmsProvider::class);

            Log::info('android_sms_gateway_debug_dispatch', [
                'provider_class' => get_class($sms),
                'provider_enabled' => $sms->enabled(),
            ]);

            if (! $sms->enabled()) {
                return; // SMSGate غير مهيأ — no-op (نفس سلوك OTP الناتج في الاستجابة)
            }

            // تحويل الرقم المحلي إلى صيغة E.164 قبل الإرسال
            $e164Phone = $this->normalizePhoneToE164($phone);

            if ($e164Phone === null) {
                Log::warning('otp_sms_invalid_phone', [
                    'phone' => substr($phone, 0, 3).'***'.substr($phone, -2),
                ]);

                return;
            }

            $result = $sms->sendSms($e164Phone, __('otp.sms_message', ['code' => $otp]));

            if (! $result['success']) {
                Log::warning('otp_sms_failed', [
                    'phone' => substr($e164Phone, 0, 3).'***'.substr($e164Phone, -2),
                    'error' => $result['error'],
                ]);
            }
        } catch (\Throwable $e) {
            // لا نوقف العملية أبداً بسبب فشل SMS — نسجل فقط
            Log::warning('otp_sms_dispatch_error', [
                'phone' => substr($phone, 0, 3).'***'.substr($phone, -2),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تحويل رقم الهاتف المحلي (10 أرقام، يبدأ بـ 05) إلى صيغة E.164 (+970...).
     * يمرر أرقام E.164 كما هي. يرجع null للأرقام غير الصالحة.
     */
    private function normalizePhoneToE164(string $phone): ?string
    {
        $phone = trim($phone);

        // إذا كان الرقم يبدأ بـ + فهو بالفعل E.164
        if (str_starts_with($phone, '+')) {
            $digits = preg_replace('/[^0-9]/', '', $phone);

            if (strlen($digits) >= 9 && strlen($digits) <= 15) {
                return '+'.$digits;
            }

            return null;
        }

        // رقم محلي فلسطيني: 10 أرقام، يبدأ بـ 059 أو 056
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) === 10 && preg_match('/^05[6-9]/', $digits)) {
            return '+970'.ltrim($digits, '0');
        }

        return null;
    }
}
