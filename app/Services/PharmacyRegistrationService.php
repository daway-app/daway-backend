<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * خدمة تسجيل الصيدليات (إنشاء + تسليم بيانات الدخول).
 *
 * الحساب يُنشأ غير مفعّل (is_active=false) مع كلمة مرور عشوائية.
 * لا يُسلّم الـ Pharmacy ID إلا بعد موافقة الأدمن (toggleStatus).
 * التسليم يتم مرة واحدة فقط (idempotent عبر delivered_at).
 */
class PharmacyRegistrationService
{
    /**
     * إنشاء صيدلية جديدة بانتظار موافقة الأدمن.
     *
     * @param  array  $data  ['pharmacy_name','phone','address','region']
     * @return Pharmacy
     *
     * @throws \RuntimeException إذا فشل توليد الـ ID
     * @throws UniqueConstraintViolationException سباق تسجيل متزامن
     */
    public function createPending(array $data): Pharmacy
    {
        $pharmacyCustomId = Pharmacy::generateUniqueCustomId();

        if ($pharmacyCustomId === null) {
            throw new \RuntimeException('تعذر توليد معرّف صيدلية فريد.');
        }

        $plainPassword = Str::random(32);

        $pharmacy = DB::transaction(function () use ($data, $pharmacyCustomId, $plainPassword) {
            $user = User::create([
                'name' => $data['pharmacy_name'],
                'email' => null,
                'phone' => $data['phone'],
                'password' => Hash::make($plainPassword),
            ]);

            $user->role = 'pharmacy';
            $user->is_active = false;
            $user->must_change_password = false;
            $user->save();
            $user->syncRoles(['pharmacy']);

            $pharmacy = new Pharmacy([
                'pharmacy_name' => $data['pharmacy_name'],
                'address' => $data['address'],
                'region' => $data['region'],
                'phone_number' => $data['phone'],
            ]);
            $pharmacy->user_id = $user->id;
            $pharmacy->pharmacy_custom_id = $pharmacyCustomId;
            $pharmacy->is_active = false;
            // delivered_at يبقى null → لم تُسلّم بعد
            $pharmacy->save();

            // نخزّن بيانات الدخول في otp_codes (نفس سلوك تسليم OTP للمريض)
            OtpCode::updateOrCreate(
                ['phone' => $data['phone']],
                [
                    'otp' => Hash::make($plainPassword),
                    'expires_at' => now()->addYear(), // صلاحية طويلة حتى يوافق الأدمن
                ]
            );

            return $pharmacy;
        });

        return $pharmacy;
    }

    /**
     * تسليم بيانات الدخول للصيدلية (Pharmacy ID + كلمة المرور).
     *
     * يُستدعى من toggleStatus عند تفعيل صيدلية مسجّلة حديثاً.
     * idempotent — لو استُدعي مرة ثانية لا يُعيد التسليم.
     *
     * @return array|null  ['pharmacy_id','password'] أو null لو سبق التسليم
     */
    public function deliver(Pharmacy $pharmacy): ?array
    {
        // idempotent — مرة واحدة فقط
        if ($pharmacy->delivered_at !== null) {
            return null;
        }

        $user = $pharmacy->user;
        if (! $user) {
            return null;
        }

        // نسترجع كلمة المرور من otp_codes (مخزّنة hashed)
        $otpRecord = OtpCode::where('phone', $user->phone)->first();
        $plainPassword = null;

        if ($otpRecord) {
            // otp_codes.otp مخزّن hashed → ما نقدر نرجّع النص الصريح
            // لكن عند إنشاء الحساب نستخدم كلمة مرور عشوائية 32 حرف
            // هنا نولّد كلمة مرور جديدة ونحدّثها
            $plainPassword = Str::random(32);
            $user->password = Hash::make($plainPassword);
            $user->save();

            // نحدّث otp_codes بالجديدة
            $otpRecord->update([
                'otp' => Hash::make($plainPassword),
                'expires_at' => now()->addYear(),
            ]);
        } else {
            // fallback: لو otp_codes مفقود، نولّد جديدة
            $plainPassword = Str::random(32);
            $user->password = Hash::make($plainPassword);
            $user->save();

            OtpCode::create([
                'phone' => $user->phone,
                'otp' => Hash::make($plainPassword),
                'expires_at' => now()->addYear(),
            ]);
        }

        $pharmacy->delivered_at = now();
        $pharmacy->save();

        return [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $plainPassword,
        ];
    }
}
