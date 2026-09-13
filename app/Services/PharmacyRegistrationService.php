<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * خدمة تسجيل الصيدليات (إنشاء + تسليم بيانات الدخول).
 *
 * صاحب الصيدلية يختار كلمة مروره بنفسه عند التسجيل. الحساب يُنشأ غير مفعّل
 * (is_active=false) وكلمة المرور تُخزّن hash كالمعتاد، مع نسخة مشفّرة قابلة
 * للاسترجاع (Crypt) على pharmacies.pending_password لغرض واحد فقط: إرسالها
 * في رسالة التسليم بعد موافقة الأدمن. فور التسليم تُصفَّر النسخة المشفّرة.
 * التسليم يتم مرة واحدة فقط (idempotent عبر delivered_at).
 */
class PharmacyRegistrationService
{
    /**
     * إنشاء صيدلية جديدة بانتظار موافقة الأدمن.
     *
     * @param  array  $data  ['pharmacy_name','phone','region','password','address'?]
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

        $pharmacy = DB::transaction(function () use ($data, $pharmacyCustomId) {
            $user = User::create([
                'name' => $data['pharmacy_name'],
                'email' => null,
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
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

            // نسخة مشفّرة قابلة للاسترجاع لرسالة التسليم — تُصفَّر فور التسليم
            $pharmacy->pending_password = Crypt::encryptString($data['password']);
            $pharmacy->save();

            return $pharmacy;
        });

        return $pharmacy;
    }

    /**
     * تسليم بيانات الدخول للصيدلية (Pharmacy ID + كلمة المرور التي اختارها).
     *
     * يُستدعى من toggleStatus عند تفعيل صيدلية مسجّلة ذاتياً — البيانات تُعرض
     * للأدمن مرة واحدة ليرسلها للصيدلية (رسالة/واتساب الآن، SMS لاحقاً).
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

        if ($pharmacy->pending_password === null) {
            return null;
        }

        $plainPassword = Crypt::decryptString($pharmacy->pending_password);

        $pharmacy->delivered_at = now();
        $pharmacy->pending_password = null;
        $pharmacy->save();

        return [
            'pharmacy_id' => $pharmacy->pharmacy_custom_id,
            'password' => $plainPassword,
        ];
    }
}
