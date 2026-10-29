<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * تغيير كلمة مرور المريض.
 *
 * التحقق من مطابقة كلمة المرور الحالية يجري صراحةً في الكنترولر بـ`Hash::check`
 * (نفس نمط `Admin\ProfileController@changePassword`) بدلًا من قاعدة
 * `current_password` — لنُعيد حمولة 422 عربية موحّدة الشكل مع رسائل النطاق،
 * ولنمنع أيضًا استخدام نفس كلمة المرور كقيمة جديدة (لا تغطّيها القاعدة).
 */
class PatientChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ];
    }
}
