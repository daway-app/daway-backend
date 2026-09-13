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
     * @param  array  $data  ['pharmacy_name','phone','region','address'?]
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
                // الشارع يُكمله صاحب الصيدلية من ملفه لاحقاً — العمود nullable
                'address' => $data['address'] ?? null,
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
            // لكن عند إنشاء الحساب نستخدم كلمة مرور عشوائية — هنا نولّد كلمة مرور جديدة
            // 8 أحرف (حروف كبيرة/صغيرة + أرقام) قابلة للكتابة من الموبايل
            $plainPassword = $this->readablePassword();
            $user->password = Hash::make($plainPassword);
            $user->save();

            // نحدّث otp_codes بالجديدة
            $otpRecord->update([
                'otp' => Hash::make($plainPassword),
                'expires_at' => now()->addYear(),
            ]);
        } else {
            // fallback: لو otp_codes مفقود، نولّد جديدة
            $plainPassword = $this->readablePassword();
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

    /**
     * كلمة مرور 8 أحرف قابلة للقراءة/الكتابة من الموبايل
     * (حروف كبيرة + صغيرة + أرقام — بدون رموز مربكة مثل l/1/O/0).
     */
    private function readablePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $length = strlen($alphabet);
        $password = '';

        for ($i = 0; $i < 8; $i++) {
            $password .= $alphabet[random_int(0, $length - 1)];
        }

        return $password;
    }
}
